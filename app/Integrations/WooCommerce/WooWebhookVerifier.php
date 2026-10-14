<?php

namespace App\Integrations\WooCommerce;

final class WooWebhookVerifier
{
    public function verify(string $rawBody, ?string $signature, string $secret): bool
    {
        if ($secret === '' || $signature === null || strlen($rawBody) > 2_000_000) {
            return false;
        }
        $decoded = base64_decode($signature, true);

        return $decoded !== false && strlen($decoded) === 32
            && hash_equals(hash_hmac('sha256', $rawBody, $secret, true), $decoded);
    }
}
