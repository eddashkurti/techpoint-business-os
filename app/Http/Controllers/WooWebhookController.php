<?php

namespace App\Http\Controllers;

use App\Integrations\WooCommerce\ProcessWooWebhook;
use App\Integrations\WooCommerce\WooIntegrationException;
use App\Integrations\WooCommerce\WooSyncService;
use Illuminate\Http\Request;

final class WooWebhookController extends Controller
{
    public function __invoke(Request $r, int $connection, WooSyncService $sync)
    {
        $raw = $r->getContent();
        if (strlen($raw) > 1_000_000) {
            abort(413);
        }
        if (preg_match('/^webhook_id=[1-9][0-9]{0,15}$/D', $raw) && ! $r->headers->has('X-WC-Webhook-Signature')) {
            return response()->json(['ping' => true], 200);
        }
        try {
            $event = $sync->ingestWebhook($connection, $raw, $r->headers->all());
        } catch (WooIntegrationException $e) {
            return response()->json(['message' => $e->getMessage()], 401);
        }
        if (! $event['duplicate']) {
            ProcessWooWebhook::dispatch($event['event_id']);
        }

return response()->json(['accepted' => true, 'duplicate' => $event['duplicate']], 200);
    }
}
