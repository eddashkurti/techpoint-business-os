<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Finance\Money;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

final class Returns
{
    public function __construct(private Posting $posting, private Stock $stock, private Finance $finance) {}

    public function credit(int $saleId, array $data): array
    {
        return $this->posting->once('sale.credit', $data + ['sale_id' => $saleId], function () use ($saleId, $data) {
            $s = DB::table('sales')->where('id', $saleId)->lockForUpdate()->firstOrFail();
            $this->posting->fiscalGate($saleId, $data);
            $rows = [];
            $net = Posting::decimal('0');
            $tax = Posting::decimal('0');
            $items = [];
            foreach ($data['lines'] as $input) {
                $l = DB::table('fulfillment_lines')->where('id', $input['fulfillment_line_id'])->lockForUpdate()->firstOrFail();
                $f = DB::table('fulfillments')->where('id', $l->fulfillment_id)->first();
                if ($f->sale_id !== $saleId || ! $f->recognized) {
                    throw new \DomainException('Kreditimi kërkon shitje të njohur nga kjo porosi.');
                }
                $q = Posting::decimal($input['quantity']);
                $newQty = $q->plus($l->credited_quantity);
                if (! $q->isPositive() || $newQty->compareTo($l->quantity) > 0) {
                    throw new \DomainException('Kreditimi tejkalon sasinë e shitjes.');
                }
                $last = $newQty->compareTo($l->quantity) === 0;
                $lineNet = $last ? Posting::decimal($l->net_total)->minus($l->credited_net) : Posting::decimal($l->net_total)->multipliedBy($q)->dividedBy($l->quantity, 2, RoundingMode::HALF_UP);
                $lineTax = $last ? Posting::decimal($l->tax_total)->minus($l->credited_tax) : Posting::decimal($l->tax_total)->multipliedBy($q)->dividedBy($l->quantity, 2, RoundingMode::HALF_UP);
                $cost = Posting::decimal($l->cost_value)->multipliedBy($q)->dividedBy($l->quantity, 8, RoundingMode::HALF_UP);
                $ledger = Posting::decimal($l->ledger_cost)->multipliedBy($q)->dividedBy($l->quantity, 2, RoundingMode::HALF_UP);
                $items[] = ['fulfillment_line_id' => $l->id, 'quantity' => (string) $q, 'cost_value' => (string) $cost, 'ledger_cost' => (string) $ledger, 'net_total' => (string) $lineNet, 'tax_total' => (string) $lineTax];
                $net = $net->plus($lineNet);
                $tax = $tax->plus($lineTax);
                $channel = DB::table('sales_channels')->where('id', $s->channel_id)->value('code');
                $rows[] = ['account' => '4000', 'debit' => (string) $lineNet, 'product_id' => $l->product_id, 'channel' => $channel];
                $rows[] = ['account' => '2200', 'debit' => (string) $lineTax, 'product_id' => $l->product_id, 'channel' => $channel];
                $rows[] = ['account' => '1100', 'credit' => (string) $lineNet->plus($lineTax), 'party_id' => $s->customer_id, 'channel' => $channel];
                DB::table('fulfillment_lines')->where('id', $l->id)->update(['credited_quantity' => (string) $newQty, 'credited_net' => (string) Posting::decimal($l->credited_net)->plus($lineNet), 'credited_tax' => (string) Posting::decimal($l->credited_tax)->plus($lineTax)]);
                $orderLine = DB::table('sale_lines')->where('id', $l->sale_line_id)->lockForUpdate()->first();
                DB::table('sale_lines')->where('id', $l->sale_line_id)->update(['credited_quantity' => (string) Posting::decimal($orderLine->credited_quantity)->plus($q)]);
            }
            $id = DB::table('sale_returns')->insertGetId(['sale_id' => $saleId, 'reference' => $data['reference'], 'credited_on' => $data['credited_on'], 'reason' => $data['reason'], 'net_total' => (string) $net, 'tax_total' => (string) $tax, 'gross_total' => (string) $net->plus($tax), 'user_id' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
            foreach ($items as $item) {
                DB::table('return_lines')->insert(['sale_return_id' => $id, ...$item]);
            }
            if ($net->plus($tax)->isPositive()) {
                $this->posting->journal('credit', $id, $data['credited_on'], $data['reason'], $rows);
            }

return ['id' => $id];
        });
    }

    public function receive(int $returnId, array $data): array
    {
        return $this->posting->once('return.receive', $data + ['return_id' => $returnId], function () use ($returnId, $data) {
            $r = DB::table('sale_returns')->where('id', $returnId)->lockForUpdate()->firstOrFail();
            $rows = [];
            $returnMap = DB::table('return_lines')->where('sale_return_id', $returnId)->lockForUpdate()->get()->keyBy('id');
            foreach ($data['lines'] as $input) {
                $line = $returnMap->get($input['return_line_id']);
                if (! $line) {
                    throw new \DomainException('Rreshti i kthimit është i pavlefshëm.');
                }
                $f = DB::table('fulfillment_lines')->where('id', $line->fulfillment_line_id)->lockForUpdate()->firstOrFail();
                $q = Posting::decimal($input['quantity']);
                if (! $q->isPositive() || $q->plus($line->received_quantity)->compareTo($line->quantity) > 0 || $q->plus($f->returned_quantity)->compareTo($f->quantity) > 0) {
                    throw new \DomainException('Sasia e kthyer tejkalon sasinë e mbetur.');
                }
                $last = $q->plus($f->returned_quantity)->compareTo($f->quantity) === 0;
                $cost = $last ? Posting::decimal($f->cost_value)->minus($f->returned_cost) : Posting::decimal($f->cost_value)->multipliedBy($q)->dividedBy($f->quantity, 8, RoundingMode::HALF_UP);
                $sourceLedger = $last ? Posting::decimal($f->ledger_cost)->minus($f->returned_ledger_cost) : Posting::decimal($f->ledger_cost)->multipliedBy($q)->dividedBy($f->quantity, 2, RoundingMode::HALF_UP);
                $move = $this->stock->move($f->product_id, (string) $q, (string) $cost, $data['condition'], 'return', 'return_receipt', $data['operation_key'].':'.$line->id, $data['reason'], $data['received_on']);
                $inventory = Posting::decimal($move->ledgerMovementValue());
                $rows[] = ['account' => '1200', 'debit' => (string) $inventory, 'product_id' => $f->product_id, 'channel' => DB::table('sales_channels')->where('id', DB::table('sales')->where('id', $r->sale_id)->value('channel_id'))->value('code')];
                $rows[] = ['account' => '5000', 'credit' => (string) $sourceLedger, 'product_id' => $f->product_id, 'channel' => DB::table('sales_channels')->where('id', DB::table('sales')->where('id', $r->sale_id)->value('channel_id'))->value('code')];
                $difference = $sourceLedger->minus($inventory);
                if ($difference->isPositive()) {
                    $rows[] = ['account' => '6900', 'debit' => (string) $difference];
                } elseif ($difference->isNegative()) {
                    $rows[] = ['account' => '6900', 'credit' => (string) $difference->negated()];
                }
                DB::table('return_lines')->where('id', $line->id)->update(['received_quantity' => (string) $q->plus($line->received_quantity)]);
                DB::table('fulfillment_lines')->where('id', $f->id)->update(['returned_quantity' => (string) $q->plus($f->returned_quantity), 'returned_cost' => (string) $cost->plus($f->returned_cost), 'returned_ledger_cost' => (string) $sourceLedger->plus($f->returned_ledger_cost)]);
                $orderLine = DB::table('sale_lines')->where('id', $f->sale_line_id)->lockForUpdate()->first();
                DB::table('sale_lines')->where('id', $f->sale_line_id)->update(['returned_quantity' => (string) Posting::decimal($orderLine->returned_quantity)->plus($q)]);
            }
            if ($rows && collect($rows)->contains(fn ($l) => Posting::decimal($l['debit'] ?? $l['credit'])->isPositive())) {
                $this->posting->journal('return_receipt', $data['operation_key'], $data['received_on'], $data['reason'], $rows);
            }
            $remaining = DB::table('return_lines')->where('sale_return_id', $returnId)->whereColumn('received_quantity', '<', 'quantity')->exists();
            DB::table('sale_returns')->where('id', $returnId)->update(['status' => $remaining ? 'partially_received' : 'received', 'updated_at' => now()]);

            return ['id' => $returnId];
        });
    }

    public function refund(int $returnId, array $data): array
    {
        return $this->posting->once('return.refund', $data + ['return_id' => $returnId], function () use ($returnId, $data) {
            $r = DB::table('sale_returns')->where('id', $returnId)->lockForUpdate()->firstOrFail();
            $s = DB::table('sales')->where('id', $r->sale_id)->lockForUpdate()->firstOrFail();
            $this->finance->cashAccount($data['account_id']);
            $this->posting->fiscalGate($r->sale_id, $data);
            $amount = Money::of($data['amount']);
            $prior = Posting::decimal((string) DB::table('refunds')->where('sale_return_id', $returnId)->sum('amount'));
            $paid = Posting::decimal((string) DB::table('payments')->where('sale_id', $r->sale_id)->sum('amount'));
            $allRefunds = Posting::decimal((string) DB::table('refunds')->join('sale_returns', 'sale_returns.id', '=', 'refunds.sale_return_id')->where('sale_returns.sale_id', $r->sale_id)->sum('refunds.amount'));
            if (! $amount->decimal()->isPositive() || $prior->plus($amount->decimal())->compareTo($r->gross_total) > 0 || $allRefunds->plus($amount->decimal())->compareTo($paid) > 0) {
                throw new \DomainException('Rimbursimi tejkalon kreditimin ose pagesat e marra.');
            }
            $id = DB::table('refunds')->insertGetId(['sale_return_id' => $returnId, 'account_id' => $data['account_id'], 'amount' => $amount->amount(), 'refunded_on' => $data['refunded_on'], 'operation_key' => $data['operation_key'], 'reason' => $data['reason'], 'user_id' => auth()->id(), 'created_at' => now()]);
            $this->posting->journal('refund', $id, $data['refunded_on'], $data['reason'], [['account' => '1100', 'debit' => $amount->amount(), 'party_id' => $s->customer_id], ['account_id' => $data['account_id'], 'credit' => $amount->amount()]]);
            Audit::record('refund.posted','return',$returnId,['amount' => $amount->amount()]);

            return ['id' => $id];
        });
    }
}
