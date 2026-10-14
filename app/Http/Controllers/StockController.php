<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\Posting;
use App\Services\Stock;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class StockController extends Controller
{
    public function adjust(Request $r, Posting $posting, Stock $stock)
    {
        Gate::authorize('inventory.adjust');
        $data = $r->validate(['operation_key' => 'required|string|min:8|max:100', 'product_id' => 'required|integer|exists:products,id', 'quantity' => 'required|string|regex:/^-?\d{1,12}(\.\d{1,6})?$/', 'value' => 'nullable|string|regex:/^\d{1,15}(\.\d{1,8})?$/', 'condition' => 'required|in:sellable,quarantine,damaged', 'accounting_date' => 'required|date', 'reason' => 'required|string|min:10|max:1000']);
        $result = $posting->once('stock.adjust', $data, function () use ($data, $posting, $stock) {
            $posting->configured();
            $q = Posting::decimal($data['quantity']);
            if ($q->isPositive() && ! isset($data['value'])) {
                throw new \DomainException('Shtimi i stokut kërkon vlerë të dokumentuar.');
            }
            $movement = $stock->move($data['product_id'], $data['quantity'], $data['value'] ?? null, $data['condition'], 'adjustment', 'adjustment', $data['operation_key'], $data['reason'], $data['accounting_date']);
            $value = Posting::decimal($movement->ledgerMovementValue());
            if ($value->isPositive()) {
                $posting->journal('adjustment', $data['operation_key'], $data['accounting_date'], $data['reason'], [['account' => '1200', 'debit' => (string) $value, 'product_id' => $data['product_id']], ['account' => '6300', 'credit' => (string) $value]]);
            } elseif ($value->isNegative()) {
                $posting->journal('adjustment', $data['operation_key'], $data['accounting_date'], $data['reason'], [['account' => '6300', 'debit' => (string) $value->negated()], ['account' => '1200', 'credit' => (string) $value->negated(), 'product_id' => $data['product_id']]]);
            }

            return ['product_id' => $data['product_id']];
        });

        return $r->expectsJson() ? response()->json($result) : back()->with('success', 'Korrigjimi u regjistrua me arsye dhe gjurmë auditimi.');
    }

    public function count(Request $r, Stock $stock)
    {
        Gate::authorize('inventory.manage');
        $data = $r->validate(['reference' => 'required|string|max:100|unique:stock_counts', 'counted_on' => 'required|date', 'reason' => 'required|string|min:5|max:1000', 'lines' => 'required|array|min:1', 'lines.*.product_id' => 'required|integer|exists:products,id|distinct', 'lines.*.quantity' => 'required|string|regex:/^\d{1,12}(\.\d{1,6})?$/']);
        $id = DB::transaction(function () use ($data, $stock) {
            $lines = [];
            foreach ($data['lines'] as $l) {
                $b = $stock->balance($l['product_id']);
                $lines[] = $l + ['book_quantity' => $b->on_hand, 'book_value' => $b->value];
            } $id = DB::table('stock_counts')->insertGetId(['reference' => $data['reference'], 'counted_on' => $data['counted_on'], 'reason' => $data['reason'], 'lines' => json_encode($lines, JSON_THROW_ON_ERROR), 'user_id' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('stock_count.created', 'stock_count', $id);

            return $id;
        });

        return $r->expectsJson() ? response()->json(['id' => $id], 201) : back()->with('success', 'Numërimi u ruajt si draft.');
    }

    public function confirmCount(Request $r, int $id, Posting $posting, Stock $stock)
    {
        Gate::authorize('inventory.adjust');
        $data = $r->validate(['operation_key' => 'required|string|min:8|max:100']);
        $result = $posting->once('stock.count', $data + ['count_id' => $id], function () use ($id, $posting, $stock) {
            $count = DB::table('stock_counts')->where('id', $id)->lockForUpdate()->firstOrFail();
            if ($count->status !== 'draft') {
                throw new \DomainException('Numërimi është konfirmuar.');
            } $rows = [];
            foreach (json_decode($count->lines, true, 512, JSON_THROW_ON_ERROR) as $l) {
                $b = $stock->balance($l['product_id']);
                if (Posting::decimal($b->on_hand)->compareTo($l['book_quantity']) !== 0 || Posting::decimal($b->value)->compareTo($l['book_value']) !== 0) {
                    throw new \DomainException('Stoku ka ndryshuar pas numërimit. Krijoni numërim të ri.');
                }
                $delta = Posting::decimal($l['quantity'])->minus($b->on_hand);
                if ($delta->isZero()) {
                    continue;
                }
                if ($delta->isPositive() && Posting::decimal($b->on_hand)->isZero()) {
                    throw new \DomainException('Stoku i ri pa kosto kërkon korrigjim të dokumentuar, jo numërim automatik.');
                }
                $cost = $delta->isPositive() ? (string) Posting::decimal($b->value)->multipliedBy($delta)->dividedBy($b->on_hand, 8, RoundingMode::HALF_UP) : null;
                $m = $stock->move($l['product_id'], (string) $delta, $cost, 'sellable', 'count', 'count', $id, $count->reason, $count->counted_on);
                $v = Posting::decimal($m->ledgerMovementValue());
                if ($v->isPositive()) {
                    $rows[] = ['account' => '1200', 'debit' => (string) $v];
                    $rows[] = ['account' => '6300', 'credit' => (string) $v];
                } elseif ($v->isNegative()) {
                    $rows[] = ['account' => '1200', 'credit' => (string) $v->negated()];
                    $rows[] = ['account' => '6300', 'debit' => (string) $v->negated()];
                }
            }
            if ($rows) {
                $posting->journal('count', $id, $count->counted_on, $count->reason, $rows);
            } DB::table('stock_counts')->where('id', $id)->update(['status' => 'confirmed', 'updated_at' => now()]);

            return ['id' => $id];
        });

        return $r->expectsJson() ? response()->json($result) : back()->with('success','Numërimi u konfirmua.');
    }
}
