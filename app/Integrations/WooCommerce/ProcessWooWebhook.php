<?php

namespace App\Integrations\WooCommerce;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessWooWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $eventId) {}

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(WooSyncService $service): void
    {
        $service->processEvent($this->eventId);
    }
}
