<?php

namespace App\Integrations\WooCommerce;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** The implementation deliberately exposes no outbound mutation operation. */
final class WooReadClient
{
    private string $apiUrl;

    public function __construct(
        string $storeUrl,
        private readonly string $consumerKey,
        private readonly string $consumerSecret,
        private readonly int $maxAttempts = 3,
        private readonly int $maxPages = 500,
        private readonly ?Closure $wait = null,
    ) {
        $parts = parse_url($storeUrl);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host'])
            || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new WooIntegrationException('WooCommerce requires a configured HTTPS store URL without embedded credentials.');
        }
        $host = strtolower($parts['host']);
        if (filter_var($host, FILTER_VALIDATE_IP) !== false || $host === 'localhost'
            || str_ends_with($host, '.localhost') || ! str_contains($host, '.')
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new WooIntegrationException('WooCommerce requires an approved public store hostname over HTTPS.');
        }
        if ($consumerKey === '' || $consumerSecret === '' || $maxAttempts < 1 || $maxAttempts > 5
            || $maxPages < 1 || $maxPages > 1000) {
            throw new WooIntegrationException('WooCommerce read connection is not configured correctly.');
        }
        $this->apiUrl = rtrim($storeUrl, '/').'/wp-json/wc/v3/';
    }

    /** @return iterable<array<string, mixed>> */
    public function records(string $resource, array $query = []): iterable
    {
        $this->assertResource($resource);
        $this->assertQuery($query);
        $perPage = max(1, min(100, (int) ($query['per_page'] ?? 100)));
        $lastPage = null;
        for ($page = 1; $page <= $this->maxPages; $page++) {
            $response = $this->request($resource, array_merge($query, ['page' => $page, 'per_page' => $perPage]));
            $rows = $response->json();
            if (! is_array($rows) || ! array_is_list($rows)) {
                throw new WooIntegrationException('WooCommerce returned an invalid collection.');
            }
            if ($page === 1 && $response->header('X-WP-TotalPages') !== null && $response->header('X-WP-TotalPages') !== '') {
                $header = $response->header('X-WP-TotalPages');
                if (! ctype_digit($header)) {
                    throw new WooIntegrationException('WooCommerce returned invalid pagination metadata.');
                }
                $lastPage = (int) $header;
                if ($lastPage > $this->maxPages) {
                    throw new WooIntegrationException('WooCommerce reconciliation exceeds the configured page limit. Narrow the import period.');
                }
            }
            foreach ($rows as $row) {
                if (! is_array($row) || empty($row['id'])) {
                    throw new WooIntegrationException('WooCommerce record is missing its external identifier.');
                }
                yield $row;
            }
            if (($lastPage !== null && $page >= $lastPage) || ($lastPage === null && count($rows) < $perPage)) {
                return;
            }
        }
        throw new WooIntegrationException('WooCommerce pagination did not finish within the configured limit.');
    }

    /** @return array<string, mixed> */
    public function record(string $resource): array
    {
        $this->assertResource($resource);
        $record = $this->request($resource, [])->json();
        if (! is_array($record) || empty($record['id'])) {
            throw new WooIntegrationException('WooCommerce returned an invalid record.');
        }

        return $record;
    }

    private function request(string $resource, array $query): Response
    {
        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            try {
                $response = Http::withBasicAuth($this->consumerKey, $this->consumerSecret)
                    ->acceptJson()->connectTimeout(5)->timeout(20)
                    ->withOptions(['allow_redirects' => false])
                    ->get($this->apiUrl.$resource, $query);
            } catch (ConnectionException) {
                if ($attempt === $this->maxAttempts) {
                    throw new WooIntegrationException('WooCommerce could not be reached after bounded read retries.');
                }
                $this->pause($attempt);

                continue;
            }
            if ($response->successful()) {
                if (strlen($response->body()) > 10_000_000) {
                    throw new WooIntegrationException('WooCommerce response exceeds the configured size limit.');
                }

                return $response;
            }
            if (($response->status() === 429 || $response->serverError()) && $attempt < $this->maxAttempts) {
                $this->pause($attempt);

                continue;
            }
            // Do not expose request URLs, credentials, response bodies or upstream exception text.
            throw new WooIntegrationException('WooCommerce read failed with HTTP '.$response->status().'.', $response->status());
        }
        throw new WooIntegrationException('WooCommerce read failed.');
    }

    private function pause(int $attempt): void
    {
        $milliseconds = 100 * (2 ** ($attempt - 1));
        if ($this->wait !== null) {
            ($this->wait)($milliseconds);
        } else {
            usleep($milliseconds * 1000);
        }
    }

    private function assertResource(string $resource): void
    {
        if (! preg_match('~^(?:products(?:/[1-9][0-9]*(?:/variations(?:/[1-9][0-9]*)?)?)?|orders(?:/[1-9][0-9]*(?:/refunds(?:/[1-9][0-9]*)?)?)?|refunds)$~D', $resource)) {
            throw new WooIntegrationException('Unsupported WooCommerce read resource.');
        }
    }

    private function assertQuery(array $query): void
    {
        $allowed = ['per_page', 'after', 'before', 'modified_after', 'modified_before', 'dates_are_gmt',
            'status', 'orderby', 'order', 'include', 'exclude', 'sku', 'type', 'parent'];
        if (array_diff(array_keys($query), $allowed) !== []) {
            throw new WooIntegrationException('Unsupported WooCommerce read query parameter.');
        }
    }
}
