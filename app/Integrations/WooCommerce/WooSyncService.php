<?php

namespace App\Integrations\WooCommerce;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use JsonException;
use Throwable;

final class WooSyncService
{
    public function __construct(private readonly WooWebhookVerifier $verifier) {}

    /** Reconciliation writes shadow records only; WooCommerce remains inventory authority. */
    public function reconcile(int $connectionId): array
    {
        if (config('techpoint.outbound_paused')) {
            throw new WooIntegrationException('Outbound operations are paused for recovery reconciliation.');
        }
        $lock = Cache::lock('woo-reconcile:'.$connectionId, 900);
        if (! $lock->get()) {
            throw new WooIntegrationException('A reconciliation is already running for this store.');
        }
        try {
            $connection = $this->connection($connectionId);
            $client = $this->client($connection);
            $counts = ['product' => 0, 'variation' => 0, 'order' => 0, 'refund' => 0];
            foreach (['products' => 'product', 'orders' => 'order', 'refunds' => 'refund'] as $resource => $type) {
                foreach ($client->records($resource) as $payload) {
                    $this->storeShadow($connectionId, $type, $payload);
                    $counts[$type]++;
                    if ($type === 'product' && ($payload['type'] ?? '') === 'variable') {
                        foreach ($client->records('products/'.$payload['id'].'/variations') as $variation) {
                            $variation['parent_product_id'] = $payload['id'];
                            $this->storeShadow($connectionId, 'variation', $variation);
                            $counts['variation']++;
                        }
                    }
                }
            }
            DB::table('integration_connections')->where('id', $connectionId)->update([
                'status' => 'ready', 'last_success_at' => now(), 'last_error' => null, 'updated_at' => now(),
            ]);

            return $counts;
        } catch (Throwable $exception) {
            $message = $exception instanceof WooIntegrationException ? $exception->getMessage() : 'WooCommerce reconciliation failed. Review the connection and retry.';
            $this->recordFailure($connectionId, $message);
            throw new WooIntegrationException($message);
        } finally {
            $lock->release();
        }
    }

    /** @return array{event_id:int, duplicate:bool} */
    public function ingestWebhook(int $connectionId, string $rawBody, array $headers): array
    {
        $connection = $this->connection($connectionId);
        $credentials = $this->credentials($connection);
        $headers = array_change_key_case($headers, CASE_LOWER);
        $signature = $this->header($headers, 'x-wc-webhook-signature');
        if (! $this->verifier->verify($rawBody, $signature, (string) ($credentials['webhook_secret'] ?? ''))) {
            throw new WooIntegrationException('WooCommerce webhook signature is invalid.');
        }
        try {
            $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw new WooIntegrationException('WooCommerce webhook body is invalid JSON.');
        }
        if (! is_array($payload) || ! isset($payload['id']) || ! preg_match('/^[1-9][0-9]*$/D', (string) $payload['id'])) {
            throw new WooIntegrationException('WooCommerce webhook is missing its external identifier.');
        }
        $topic = $this->header($headers, 'x-wc-webhook-topic');
        if (! in_array($topic, ['order.created', 'order.updated', 'order.deleted', 'product.created', 'product.updated', 'product.deleted'], true)) {
            throw new WooIntegrationException('WooCommerce webhook topic is not supported.');
        }
        $hash = hash('sha256', $rawBody);
        $identity = hash('sha256', explode('.', $topic)[0]."\0".$rawBody);
        // Delivery headers are not covered by the body HMAC. A body hash remains stable when they are altered.
        $inserted = DB::table('integration_events')->insertOrIgnore([
            'connection_id' => $connectionId, 'source' => 'woocommerce_webhook', 'event_id' => $identity,
            'topic' => $topic, 'payload' => json_encode(['id' => $payload['id']], JSON_THROW_ON_ERROR),
            'raw_body_hash' => $hash, 'status' => 'pending', 'attempts' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $id = DB::table('integration_events')->where('connection_id', $connectionId)
            ->where('source', 'woocommerce_webhook')->where('event_id', $identity)->value('id');

        return ['event_id' => (int) $id, 'duplicate' => $inserted === 0];
    }

    /** Fetch authoritative current data; stale or reordered webhook payloads cannot overwrite a newer snapshot. */
    public function processEvent(int $eventId): void
    {
        if (config('techpoint.outbound_paused')) {
            throw new WooIntegrationException('Outbound operations are paused for recovery reconciliation.');
        }
        $lock = Cache::lock('woo-event:'.$eventId, 120);
        if (! $lock->get()) {
            throw new WooIntegrationException('This webhook is already processing.');
        }
        try {
            $event = DB::table('integration_events')->where('id', $eventId)->first();
            if ($event === null || $event->source !== 'woocommerce_webhook') {
                throw new WooIntegrationException('WooCommerce webhook event was not found.');
            }
            if ($event->status === 'processed') {
                return;
            }
            DB::table('integration_events')->where('id', $eventId)->increment('attempts');
            $payload = json_decode($event->payload, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            $type = str_starts_with($event->topic, 'order.') ? 'order' : 'product';
            $resource = $type === 'order' ? 'orders' : 'products';
            $client = $this->client($this->connection((int) $event->connection_id));
            try {
                $current = $client->record($resource.'/'.$payload['id']);
                $this->storeShadow((int) $event->connection_id, $type, $current);
            } catch (WooIntegrationException $exception) {
                if ($exception->getCode() !== 404 || ! str_ends_with($event->topic, '.deleted')) {
                    throw $exception;
                }
                // Preserve history. A verified absent source record cannot reverse ERP stock or accounting.
                DB::transaction(function () use ($event, $type, $payload): void {
                    $shadow = DB::table('shadow_records')->where('connection_id', $event->connection_id)
                        ->where('external_type', $type)->where('external_id', (string) $payload['id'])->lockForUpdate()->first();
                    if ($shadow !== null) {
                        $saved = json_decode($shadow->payload, true, 512, JSON_THROW_ON_ERROR);
                        $saved['_source_deleted_observed_at'] = now()->toISOString();
                        DB::table('shadow_records')->where('id', $shadow->id)->update([
                            'payload' => json_encode($saved, JSON_THROW_ON_ERROR), 'updated_at' => now(),
                        ]);
                    }
                }, 3);
            }
            DB::table('integration_events')->where('id', $eventId)->update([
                'status' => 'processed', 'processed_at' => now(), 'last_error' => null, 'updated_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $message = $exception instanceof WooIntegrationException ? $exception->getMessage() : 'WooCommerce webhook processing failed.';
            DB::table('integration_events')->where('id', $eventId)->update([
                'status' => 'failed', 'last_error' => $message, 'updated_at' => now(),
            ]);
            throw new WooIntegrationException($message);
        } finally {
            $lock->release();
        }
    }

    /** @return array<string, mixed> */
    private function credentials(object $connection): array
    {
        try {
            $data = json_decode(Crypt::decryptString((string) $connection->credentials), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new WooIntegrationException('WooCommerce credentials are unavailable or cannot be decrypted.');
        }
        if (! is_array($data)) {
            throw new WooIntegrationException('WooCommerce credentials are invalid.');
        }

        return $data;
    }

    private function connection(int $connectionId): object
    {
        $connection = DB::table('integration_connections')->where('id', $connectionId)->first();
        if ($connection === null || $connection->type !== 'woocommerce' || $connection->status === 'disabled') {
            throw new WooIntegrationException('WooCommerce connection is unavailable.');
        }

        return $connection;
    }

    private function client(object $connection): WooReadClient
    {
        $credentials = $this->credentials($connection);
        $allowedHosts = config('integrations.woocommerce.allowed_hosts', ['techpoint-ks.com', 'www.techpoint-ks.com']);
        $host = strtolower((string) parse_url((string) $connection->base_url, PHP_URL_HOST));
        if (! is_array($allowedHosts) || ! in_array($host, $allowedHosts, true)) {
            throw new WooIntegrationException('WooCommerce store hostname is outside the operator-configured allowlist.');
        }

        return new WooReadClient((string) $connection->base_url,
            (string) ($credentials['consumer_key'] ?? ''), (string) ($credentials['consumer_secret'] ?? ''),
            maxPages: (int) config('integrations.woocommerce.max_pages', 500));
    }

    private function storeShadow(int $connectionId, string $type, array $payload): void
    {
        // Import business facts without billing/shipping contact data or arbitrary metadata.
        unset($payload['billing'],$payload['shipping'],$payload['customer_ip_address'],$payload['customer_user_agent'],$payload['customer_note'],$payload['meta_data']);
        foreach (['line_items', 'shipping_lines', 'fee_lines', 'coupon_lines'] as $collection) {
            if (isset($payload[$collection]) && is_array($payload[$collection])) {
                foreach ($payload[$collection] as &$line) {
                    unset($line['meta_data']);
                }
            }
        }
        $externalId = (string) $payload['id'];
        $modified = ! empty($payload['date_modified_gmt'])
            ? CarbonImmutable::parse($payload['date_modified_gmt'], 'UTC')->format('Y-m-d H:i:s') : null;
        DB::transaction(function () use ($connectionId, $type, $externalId, $payload, $modified): void {
            $identity = ['connection_id' => $connectionId, 'external_type' => $type, 'external_id' => $externalId];
            DB::table('shadow_records')->insertOrIgnore(array_merge($identity, [
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'source_modified_at' => $modified,
                'created_at' => now(), 'updated_at' => now(),
            ]));
            $row = DB::table('shadow_records')->where($identity)->lockForUpdate()->first();
            if ($row->source_modified_at === null || ($modified !== null && $modified >= $row->source_modified_at)) {
                DB::table('shadow_records')->where('id', $row->id)->update([
                    'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'source_modified_at' => $modified,
                    'updated_at' => now(),
                ]);
            }
            DB::table('external_mappings')->insertOrIgnore(array_merge($identity, [
                'internal_type' => 'shadow_record', 'internal_id' => $row->id,
                'created_at' => now(), 'updated_at' => now(),
            ]));
        }, 3);
    }

    private function recordFailure(int $connectionId, string $message): void
    {
        DB::table('integration_connections')->where('id', $connectionId)->update([
            'status' => 'error', 'last_error_at' => now(), 'last_error' => $message, 'updated_at' => now(),
        ]);
    }

    private function header(array $headers, string $name): string
    {
        $value = $headers[$name] ?? '';

        return is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
    }
}
