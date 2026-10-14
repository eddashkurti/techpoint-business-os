<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Finance\Money;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

final class Selling
{
    public function __construct(private Posting $posting, private Stock $stock, private Finance $finance) {}

    public function quote(array $data): array
    {
        $settings = $this->posting->configured();
        $net = Posting::decimal('0');
        $tax = Posting::decimal('0');
        $lines = [];
        foreach ($data['lines'] as $input) {
            $p = DB::table('products')->where('id', $input['product_id'])->where('active', true)->firstOrFail();
            $price = Posting::decimal($input['unit_price'] ?? $p->selling_price);
            $qty = Posting::decimal($input['quantity']);
            $discount = Money::of($input['discount'] ?? '0');
            if (! $qty->isPositive() || $price->isNegative() || $discount->isNegative()) {
                throw new \DomainException('Sasia dhe çmimi janë të pavlefshme.');
            }
            if ($price->compareTo($p->selling_price) !== 0 && ! auth()->user()->permitted('sales.discount')) {
                throw new \DomainException('Ndryshimi i çmimit kërkon leje.');
            }
            if (! $discount->isZero() && ! auth()->user()->permitted('sales.discount')) {
                throw new \DomainException('Zbritja kërkon leje.');
            }
            $lineNet = $price->multipliedBy($qty)->minus($discount->decimal())->toScale(2, RoundingMode::HALF_UP);
            if ($lineNet->isNegative()) {
                throw new \DomainException('Zbritja tejkalon vlerën e rreshtit.');
            }
            $rate = ($settings['vat_registered'] ?? false) ? $p->tax_rate : '0';
            $lineTax = $lineNet->multipliedBy($rate)->dividedBy('100', 2, RoundingMode::HALF_UP);
            $lines[] = ['product_id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'quantity' => (string) $qty, 'unit_price' => (string) $price, 'discount' => $discount->amount(), 'tax_rate' => $rate, 'net_total' => (string) $lineNet, 'tax_total' => (string) $lineTax];
            $net = $net->plus($lineNet);
            $tax = $tax->plus($lineTax);
        }
        $delivery = Money::of($data['delivery_charge'] ?? '0');
        if ($delivery->isNegative()) {
            throw new \DomainException('Tarifa e dërgesës nuk mund të jetë negative.');
        }
        $deliveryTax = ($settings['vat_registered'] ?? false) ? $delivery->decimal()->multipliedBy($settings['delivery_tax_rate'])->dividedBy('100', 2, RoundingMode::HALF_UP) : Posting::decimal('0.00');

        return ['net' => (string) $net->plus($delivery->decimal())->toScale(2), 'tax' => (string) $tax->plus($deliveryTax)->toScale(2), 'gross' => (string) $net->plus($tax)->plus($delivery->decimal())->plus($deliveryTax)->toScale(2), 'delivery_tax' => (string) $deliveryTax, 'lines' => $lines];
    }

    public function create(array $data): array
    {
        return $this->posting->once('sale.create', $data, function () use ($data) {
            $settings = $this->posting->configured();
            $quote = $this->quote($data);
            $id = DB::table('sales')->insertGetId(['reference' => $data['reference'], 'channel_id' => $data['channel_id'], 'customer_id' => $data['customer_id'] ?? null, 'recognition_policy' => $settings['recognition_policy'], 'fiscal_required' => $settings['fiscal_required'], 'ordered_on' => $data['ordered_on'], 'net_total' => $quote['net'], 'tax_total' => $quote['tax'], 'gross_total' => $quote['gross'], 'delivery_charge' => $data['delivery_charge'] ?? '0', 'delivery_tax' => $quote['delivery_tax'], 'notes' => $data['notes'] ?? null, 'user_id' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
            $lines = $quote['lines'];
            usort($lines, fn ($a, $b) => $a['product_id'] <=> $b['product_id']);
            foreach ($lines as $line) {
                $this->stock->reserve($line['product_id'], $line['quantity']);
                DB::table('sale_lines')->insert(['sale_id' => $id, ...$line]);
            }
            Audit::record('sale.created', 'sale', $id, ['channel_id' => $data['channel_id']]);

            return ['id' => $id];
        });
    }

    public function dispatch(int $saleId, array $data): array
    {
        return $this->posting->once('sale.dispatch', $data + ['sale_id' => $saleId], function () use ($saleId, $data) {
            $s = DB::table('sales')->where('id', $saleId)->lockForUpdate()->firstOrFail();
            if ($s->order_status !== 'accepted') {
                throw new \DomainException('Porosia nuk është aktive.');
            } $this->posting->fiscalGate($saleId, $data);
            $lineMap = DB::table('sale_lines')->where('sale_id', $saleId)->orderBy('product_id')->lockForUpdate()->get()->keyBy('id');
            $first = ! DB::table('fulfillments')->where('sale_id', $saleId)->exists();
            $id = DB::table('fulfillments')->insertGetId(['sale_id' => $saleId, 'dispatched_on' => $data['dispatched_on'], 'delivery_charge' => $first ? $s->delivery_charge : '0', 'delivery_tax' => $first ? $s->delivery_tax : '0', 'operation_key' => $data['operation_key'], 'user_id' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
            $rows = [];
            $items = $data['lines'];
            usort($items, fn ($a, $b) => (int) $a['sale_line_id'] <=> (int) $b['sale_line_id']);
            foreach ($items as $item) {
                $l = $lineMap->get($item['sale_line_id']);
                $q = Posting::decimal($item['quantity']);
                if (! $l || ! $q->isPositive() || $q->plus($l->dispatched_quantity)->compareTo($l->quantity) > 0) {
                    throw new \DomainException('Sasia e dërguar tejkalon porosinë.');
                }
                $previous = DB::table('fulfillment_lines')->where('sale_line_id', $l->id)->get();
                $priorNet = Posting::decimal('0');
                $priorTax = Posting::decimal('0');
                foreach ($previous as $p) {
                    $priorNet = $priorNet->plus($p->net_total);
                    $priorTax = $priorTax->plus($p->tax_total);
                }
                $last = $q->plus($l->dispatched_quantity)->compareTo($l->quantity) === 0;
                $net = $last ? Posting::decimal($l->net_total)->minus($priorNet) : Posting::decimal($l->net_total)->multipliedBy($q)->dividedBy($l->quantity, 2, RoundingMode::HALF_UP);
                $tax = $last ? Posting::decimal($l->tax_total)->minus($priorTax) : Posting::decimal($l->tax_total)->multipliedBy($q)->dividedBy($l->quantity, 2, RoundingMode::HALF_UP);
                $cost = $this->stock->move($l->product_id, (string) $q->negated(), null, 'sellable', 'dispatch', 'fulfillment', $id, 'Dërgim '.$s->reference, $data['dispatched_on'], true);
                DB::table('fulfillment_lines')->insert(['fulfillment_id' => $id, 'sale_line_id' => $l->id, 'product_id' => $l->product_id, 'quantity' => (string) $q, 'cost_value' => (string) Posting::decimal($cost->movementValue)->negated(), 'ledger_cost' => (string) Posting::decimal($cost->ledgerMovementValue())->negated(), 'net_total' => (string) $net, 'tax_total' => (string) $tax]);
                DB::table('sale_lines')->where('id', $l->id)->update(['dispatched_quantity' => (string) $q->plus($l->dispatched_quantity)]);
                $expense = $s->recognition_policy === 'delivery' ? '1210' : '5000';
                $ledger = (string) Posting::decimal($cost->ledgerMovementValue())->negated();
                $rows[] = ['account' => $expense, 'debit' => $ledger, 'product_id' => $l->product_id, 'channel' => DB::table('sales_channels')->where('id', $s->channel_id)->value('code')];
                $rows[] = ['account' => '1200', 'credit' => $ledger, 'product_id' => $l->product_id, 'channel' => DB::table('sales_channels')->where('id', $s->channel_id)->value('code')];
                if ($s->recognition_policy === 'dispatch') {
                    $rows = [...$rows, ...$this->revenueRows($s, $l->product_id, (string) $net, (string) $tax)];
                }
            }
            if ($first && $s->recognition_policy === 'dispatch') {
                $rows = [...$rows, ...$this->deliveryRevenueRows($s, $s->delivery_charge, $s->delivery_tax)];
            }
            if ($rows) {
                $this->posting->journal('dispatch', $id, $data['dispatched_on'], 'Dërgim i porosisë '.$s->reference, $rows);
            }
            DB::table('fulfillments')->where('id', $id)->update(['recognized' => $s->recognition_policy === 'dispatch']);
            DB::table('deliveries')->insert(['fulfillment_id' => $id, 'courier_id' => $data['courier_id'] ?? null, 'tracking_reference' => $data['tracking_reference'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
            $remaining = DB::table('sale_lines')->where('sale_id', $saleId)->whereColumn('dispatched_quantity', '<', 'quantity')->exists();
            DB::table('sales')->where('id', $saleId)->update(['fulfillment_status' => $remaining ? 'partially_dispatched' : 'dispatched', 'delivery_status' => 'in_transit', 'updated_at' => now()]);

            return ['id' => $id];
        });
    }

    private function revenueRows(object $sale, int $productId, string $net, string $tax): array
    {
        $channel = DB::table('sales_channels')->where('id', $sale->channel_id)->value('code');

        return [['account' => '1100', 'debit' => (string) Posting::decimal($net)->plus($tax), 'party_id' => $sale->customer_id, 'channel' => $channel, 'product_id' => $productId], ['account' => '4000', 'credit' => $net, 'channel' => $channel, 'product_id' => $productId], ['account' => '2200', 'credit' => $tax, 'channel' => $channel, 'product_id' => $productId]];
    }

    private function deliveryRevenueRows(object $sale, string $net, string $tax): array
    {
        $channel = DB::table('sales_channels')->where('id', $sale->channel_id)->value('code');

        return [['account' => '1100', 'debit' => (string) Posting::decimal($net)->plus($tax), 'party_id' => $sale->customer_id, 'channel' => $channel], ['account' => '4010', 'credit' => $net, 'channel' => $channel], ['account' => '2200', 'credit' => $tax, 'channel' => $channel]];
    }

    public function deliver(int $fulfillmentId, array $data): array
    {
        return $this->posting->once('sale.deliver', $data + ['fulfillment_id' => $fulfillmentId], function () use ($fulfillmentId, $data) {
            $f = DB::table('fulfillments')->where('id', $fulfillmentId)->lockForUpdate()->firstOrFail();
            if ($f->status !== 'dispatched') {
                throw new \DomainException('Dërgesa nuk është në pritje.');
            }
            $s = DB::table('sales')->where('id', $f->sale_id)->lockForUpdate()->firstOrFail();
            $rows = [];
            if (! $f->recognized) {
                foreach (DB::table('fulfillment_lines')->where('fulfillment_id', $fulfillmentId)->get() as $l) {
                    $rows = [...$rows, ...$this->revenueRows($s, $l->product_id, $l->net_total, $l->tax_total)];
                    $cost = $l->ledger_cost;
                    $rows[] = ['account' => '5000', 'debit' => $cost, 'product_id' => $l->product_id, 'channel' => DB::table('sales_channels')->where('id', $s->channel_id)->value('code')];
                    $rows[] = ['account' => '1210', 'credit' => $cost, 'product_id' => $l->product_id, 'channel' => DB::table('sales_channels')->where('id', $s->channel_id)->value('code')];
                }
            }
            if (! $f->recognized) {
                $rows = [...$rows, ...$this->deliveryRevenueRows($s, $f->delivery_charge, $f->delivery_tax)];
            }
            if ($rows) {
                $this->posting->journal('delivery', $fulfillmentId, $data['delivered_on'], 'Dorëzim i konfirmuar '.$s->reference, $rows);
            }
            DB::table('fulfillments')->where('id', $fulfillmentId)->update(['status' => 'delivered', 'recognized' => true, 'delivered_on' => $data['delivered_on'], 'updated_at' => now()]);
            DB::table('deliveries')->where('fulfillment_id', $fulfillmentId)->update(['status' => 'delivered', 'updated_at' => now()]);
            $pending = DB::table('fulfillments')->where('sale_id', $s->id)->where('status', '!=', 'delivered')->exists();
            $unshipped = $s->order_status !== 'cancelled' && DB::table('sale_lines')->where('sale_id', $s->id)->whereColumn('dispatched_quantity', '<', 'quantity')->exists();
            DB::table('sales')->where('id', $s->id)->update(['delivery_status' => ($pending || $unshipped) ? 'partially_delivered' : 'delivered', 'updated_at' => now()]);
            Audit::record('delivery.confirmed', 'fulfillment', $fulfillmentId);

            return ['id' => $fulfillmentId];
        });
    }

    public function cancel(int $saleId, array $data): array
    {
        return $this->posting->once('sale.cancel', $data + ['sale_id' => $saleId], function () use ($saleId, $data) {
            $s = DB::table('sales')->where('id', $saleId)->lockForUpdate()->firstOrFail();
            if ($s->order_status !== 'accepted') {
                throw new \DomainException('Porosia nuk është aktive.');
            }
            if (! DB::table('sale_lines')->where('sale_id', $saleId)->whereColumn('dispatched_quantity', '<', 'quantity')->exists()) {
                throw new \DomainException('Porosia është dërguar plotësisht; përdorni kthimin e autorizuar.');
            }
            foreach (DB::table('sale_lines')->where('sale_id', $saleId)->orderBy('product_id')->lockForUpdate()->get() as $l) {
                $remaining = Posting::decimal($l->quantity)->minus($l->dispatched_quantity);
                if ($remaining->isPositive()) {
                    $this->stock->release($l->product_id, (string) $remaining);
                }
            }
            DB::table('sales')->where('id', $saleId)->update(['order_status' => 'cancelled', 'updated_at' => now()]);
            Audit::record('sale.cancelled', 'sale', $saleId, ['reason' => $data['reason']]);

            return ['id' => $saleId];
        });
    }

    public function payment(int $saleId, array $data): array
    {
        return $this->posting->once('sale.payment', $data + ['sale_id' => $saleId], function () use ($saleId, $data) {
            $s = DB::table('sales')->where('id', $saleId)->lockForUpdate()->firstOrFail();
            $this->posting->fiscalGate($saleId, $data);
            $this->finance->cashAccount($data['account_id']);
            $amount = Money::of($data['amount']);
            $paid = Posting::decimal((string) DB::table('payments')->where('sale_id', $saleId)->sum('amount'));
            $credits = Posting::decimal((string) DB::table('sale_returns')->where('sale_id', $saleId)->sum('gross_total'));
            $refunded = Posting::decimal((string) DB::table('refunds')->join('sale_returns', 'sale_returns.id', '=', 'refunds.sale_return_id')->where('sale_returns.sale_id', $saleId)->sum('refunds.amount'));
            if (! $amount->decimal()->isPositive() || $paid->minus($refunded)->plus($amount->decimal())->compareTo(Posting::decimal($this->recognizedGross($saleId))->minus($credits)) > 0) {
                throw new \DomainException('Pagesa tejkalon borxhin e njohur të klientit. Parapagimet kërkojnë rrjedhë të veçantë fiskale.');
            }
            $id = DB::table('payments')->insertGetId(['kind' => 'customer', 'sale_id' => $saleId, 'account_id' => $data['account_id'], 'amount' => $amount->amount(), 'method' => $data['method'], 'reference' => $data['reference'], 'operation_key' => $data['operation_key'], 'paid_on' => $data['paid_on'], 'user_id' => auth()->id(), 'created_at' => now()]);
            $this->posting->journal('customer_payment', $id, $data['paid_on'], 'Pagesë klienti '.$s->reference, [['account_id' => $data['account_id'], 'debit' => $amount->amount()], ['account' => '1100', 'credit' => $amount->amount(), 'party_id' => $s->customer_id]]);
            DB::table('sales')->where('id', $saleId)->update(['payment_status' => $paid->plus($amount->decimal())->compareTo($s->gross_total) === 0 ? 'paid' : 'partially_paid', 'updated_at' => now()]);

            return ['id' => $id];
        });
    }

    public function recognizedGross(int $saleId): string
    {
        $lines = DB::table('fulfillment_lines as l')->join('fulfillments as f', 'f.id', '=', 'l.fulfillment_id')->where('f.sale_id', $saleId)->where('f.recognized', true)->selectRaw('COALESCE(SUM(l.net_total+l.tax_total),0) total')->first();
        $shipping = DB::table('fulfillments')->where('sale_id', $saleId)->where('recognized', true)->selectRaw('COALESCE(SUM(delivery_charge+delivery_tax),0) total')->first();

        return (string) Posting::decimal($lines->total)->plus($shipping->total);
    }
}
