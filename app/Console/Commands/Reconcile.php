<?php

namespace App\Console\Commands;

use App\Services\Posting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class Reconcile extends Command
{
    protected $signature = 'techpoint:reconcile {--json}';

    protected $description = 'Verify immutable ledgers and stock projections without changing records';

    public function handle(): int
    {
        $errors = [];
        foreach (DB::table('journals')->get() as $j) {
            $s = DB::table('journal_lines')->where('journal_id', $j->id)->selectRaw('SUM(debit) debit, SUM(credit) credit')->first();
            if (Posting::decimal((string) $s->debit)->compareTo($j->debit_total) !== 0 || Posting::decimal((string) $s->credit)->compareTo($j->credit_total) !== 0 || Posting::decimal($j->debit_total)->compareTo($j->credit_total) !== 0) {
                $errors[] = 'journal:'.$j->id;
            }
        }
        foreach (DB::table('stock_balances')->get() as $b) {
            $s = DB::table('stock_movements')->where('product_id', $b->product_id)->where('condition', $b->condition)->where('warehouse_id', $b->warehouse_id)->selectRaw('COALESCE(SUM(quantity),0) quantity, COALESCE(SUM(value),0) value')->first();
            if (Posting::decimal((string) $s->quantity)->compareTo($b->on_hand) !== 0 || Posting::decimal((string) $s->value)->compareTo($b->value) !== 0 || Posting::decimal($b->reserved)->compareTo($b->on_hand) > 0) {
                $errors[] = 'stock:'.$b->id;
            }
        }
        $summary = ['ok' => $errors === [], 'errors' => $errors, 'journals' => DB::table('journals')->count(), 'stock_movements' => DB::table('stock_movements')->count(), 'unresolved_fiscal' => DB::table('fiscal_documents')->whereIn('status', ['submitted', 'outcome_unknown'])->count(), 'failed_integrations' => DB::table('integration_events')->where('status', 'failed')->count(), 'outbound_paused' => (bool) config('techpoint.outbound_paused')];
        $this->line(json_encode($summary, JSON_THROW_ON_ERROR));

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }
}
