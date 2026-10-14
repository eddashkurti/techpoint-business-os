<?php

namespace App\Integrations\WooCommerce;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class WooReconcileCommand extends Command
{
    protected $signature = 'integrations:woo-reconcile {connection? : Connection ID to reconcile}';

    protected $description = 'Read WooCommerce into shadow records without changing live stock or financial postings';

    public function handle(WooSyncService $service): int
    {
        $query = DB::table('integration_connections')->where('type', 'woocommerce')->where('status', '!=', 'disabled');
        if ($this->argument('connection') !== null) {
            $query->where('id', $this->argument('connection'));
        }
        $failed = false;
        foreach ($query->orderBy('id')->get(['id']) as $connection) {
            try {
                $counts = $service->reconcile((int) $connection->id);
                $this->info('Connection '.$connection->id.' reconciled into shadow records: '.json_encode($counts));
            } catch (WooIntegrationException $exception) {
                $failed = true;
                $this->error('Connection '.$connection->id.': '.$exception->getMessage());
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
