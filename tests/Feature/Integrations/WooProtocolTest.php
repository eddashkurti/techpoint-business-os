<?php

namespace Tests\Feature\Integrations;

use App\Integrations\WooCommerce\WooIntegrationException;
use App\Integrations\WooCommerce\WooReadClient;
use App\Integrations\WooCommerce\WooWebhookVerifier;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class WooProtocolTest extends TestCase
{
    public function test_pagination_reads_every_page_and_never_exposes_credentials_in_url(): void
    {
        Http::preventStrayRequests();
        Http::fakeSequence()->push([['id' => 7]], 200, ['X-WP-TotalPages' => '2'])
            ->push([['id' => 8]], 200, ['X-WP-TotalPages' => '2']);
        $rows = iterator_to_array((new WooReadClient('https://store.example', 'test-read-key', 'test-read-secret'))->records('orders'));
        $this->assertSame([7, 8], array_column($rows, 'id'));
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_contains($request->url(), '/wp-json/wc/v3/orders')
            && $request['page'] === 2 && ! str_contains($request->url(), 'test-read-secret'));
    }

    public function test_transient_reads_retry_but_authentication_failure_does_not(): void
    {
        Http::fakeSequence()->push([], 503)->push([], 429)->push([['id' => 7]], 200, ['X-WP-TotalPages' => '1']);
        $waits = [];
        $client = new WooReadClient('https://store.example', 'test-read-key', 'test-read-secret',
            wait: function (int $milliseconds) use (&$waits): void {
                $waits[] = $milliseconds;
            });
        $this->assertCount(1, iterator_to_array($client->records('products')));
        $this->assertSame([100, 200], $waits);
        Http::assertSentCount(3);
    }

    public function test_authentication_failure_does_not_retry_or_expose_upstream_details(): void
    {
        $client = new WooReadClient('https://store.example', 'test-read-key', 'test-read-secret');
        Http::fakeSequence()->push(['error' => 'sensitive upstream detail'], 401);
        try {
            iterator_to_array($client->records('orders'));
            $this->fail('Authentication failure must be surfaced.');
        } catch (WooIntegrationException $exception) {
            $this->assertSame('WooCommerce read failed with HTTP 401.', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_resource_injection_and_http_credentials_are_refused_before_network(): void
    {
        Http::preventStrayRequests();
        $client = new WooReadClient('https://store.example', 'test-read-key', 'test-read-secret');
        foreach (['../../admin', 'https://other.example', 'products?consumer_secret=exfiltrate'] as $resource) {
            try {
                iterator_to_array($client->records($resource));
                $this->fail('Unsafe resource was accepted.');
            } catch (WooIntegrationException) { /* required */
            }
        }
        $this->expectException(WooIntegrationException::class);
        new WooReadClient('http://store.example', 'test-read-key', 'test-read-secret');
    }

    public function test_webhook_hmac_uses_exact_raw_bytes_and_refuses_empty_secret(): void
    {
        $body = "{\n  \"id\": 9, \"total\": \"17.50\"\n}";
        $signature = base64_encode(hash_hmac('sha256', $body, 'test-webhook-secret', true));
        $verifier = new WooWebhookVerifier;
        $this->assertTrue($verifier->verify($body, $signature, 'test-webhook-secret'));
        $this->assertFalse($verifier->verify(json_encode(json_decode($body, true)), $signature, 'test-webhook-secret'));
        $this->assertFalse($verifier->verify($body, $signature, ''));
        $this->assertFalse($verifier->verify($body, 'not-base64!', 'test-webhook-secret'));
    }
}
