<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Finance\Money;
use App\Domain\Purchasing\LandedCostAllocator;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

final class Purchasing
{
    public function __construct(private Posting $posting, private Stock $stock) {}

    public function create(array $data): int
    {
        return DB::transaction(function () use ($data) {
            $id = DB::table('purchase_orders')->insertGetId(['reference' => $data['reference'], 'supplier_id' => $data['supplier_id'], 'invoice_reference' => $data['invoice_reference'] ?? null, 'ordered_on' => $data['ordered_on'], 'currency' => $data['currency'], 'exchange_rate' => $data['exchange_rate'], 'exchange_rate_source' => $data['exchange_rate_source'], 'status' => 'draft', 'notes' => $data['notes'] ?? null, 'user_id' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
            foreach ($data['lines'] as $line) {
                $product = DB::table('products')->where('id', $line['product_id'])->firstOrFail();
                $conversion = Posting::decimal($product->unit_conversion);
                $entered = Posting::decimal($line['quantity']);
                if (! $conversion->isPositive() || ! $entered->isPositive()) {
                    throw new \DomainException('Konvertimi dhe sasia duhet të jenë pozitive.');
                }
                DB::table('purchase_lines')->insert(['purchase_order_id' => $id, 'product_id' => $line['product_id'], 'quantity' => (string) $entered->multipliedBy($conversion)->toScale(6, RoundingMode::UNNECESSARY), 'unit_price' => (string) Posting::decimal($line['unit_price'])->dividedBy($conversion, 8, RoundingMode::HALF_UP), 'entered_quantity' => (string) $entered, 'entered_unit' => $product->purchase_unit, 'conversion' => (string) $conversion, 'invoice_value' => (string) $entered->multipliedBy($line['unit_price'])->toScale(8, RoundingMode::HALF_UP), 'weight' => $line['weight'] ?? '0', 'volume' => $line['volume'] ?? '0']);
            }
            Audit::record('purchase.created', 'purchase', $id);

            return $id;
        });
    }

    public function allocations(int $id): array
    {
        $order = DB::table('purchase_orders')->where('id', $id)->firstOrFail();
        $lines = DB::table('purchase_lines')->where('purchase_order_id', $id)->orderBy('product_id')->get();
        $result = [];
        foreach (DB::table('landed_cost_components')->where('purchase_order_id', $id)->get() as $cost) {
            $amount = Money::of($cost->amount_eur);
            if ($cost->category === 'import_vat') {
                $amount = (new LandedCostAllocator)->splitVat($amount, $cost->recoverable_percent)->nonrecoverable;
            }
            if (! $cost->capitalizable) {
                continue;
            }
            $weights = [];
            foreach ($lines as $line) {
                $weights[$line->id] = (string) match ($cost->allocation_basis) {
                    'value' => Posting::decimal($line->invoice_value ?? (string) Posting::decimal($line->quantity)->multipliedBy($line->unit_price))->toScale(12, RoundingMode::HALF_UP),
                    'quantity' => Posting::decimal($line->quantity),
                    'weight' => Posting::decimal($line->quantity)->multipliedBy($line->weight),
                    'volume' => Posting::decimal($line->quantity)->multipliedBy($line->volume),
                    default => throw new \DomainException('Baza e shpërndarjes nuk njihet.'),
                };
            }
            $result[$cost->id] = array_map(fn ($a) => $a->amount(), (new LandedCostAllocator)->allocate($amount, $weights));
        }

        return $result;
    }

    public function receive(int $orderId, array $data): array
    {
        return $this->posting->once('purchase.receive', $data + ['order_id' => $orderId], function () use ($orderId, $data) {
            $this->posting->configured();
            $order = DB::table('purchase_orders')->where('id', $orderId)->lockForUpdate()->firstOrFail();
            if ($order->status === 'received') {
                throw new \DomainException('Porosia është pranuar plotësisht.');
            }
            $lines = DB::table('purchase_lines')->where('purchase_order_id', $orderId)->orderBy('product_id')->lockForUpdate()->get()->keyBy('id');
            $allocations = $this->allocations($orderId);
            $receipt = DB::table('purchase_receipts')->insertGetId(['purchase_order_id' => $orderId, 'reference' => $data['reference'], 'received_on' => $data['received_on'], 'operation_key' => $data['operation_key'], 'user_id' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
            $journal = [];
            $items = $data['lines'];
            usort($items, fn ($a, $b) => (int) $a['purchase_line_id'] <=> (int) $b['purchase_line_id']);
            foreach ($items as $item) {
                $line = $lines->get($item['purchase_line_id']);
                $q = Posting::decimal($item['quantity']);
                if (! $line || ! $q->isPositive() || $q->plus($line->received_quantity)->compareTo($line->quantity) > 0) {
                    throw new \DomainException('Sasia e pranuar tejkalon porosinë.');
                }
                $fullBase = Posting::decimal($line->invoice_value ?? (string) Posting::decimal($line->quantity)->multipliedBy($line->unit_price))->multipliedBy($order->exchange_rate)->toScale(8, RoundingMode::HALF_UP);
                $base = $q->plus($line->received_quantity)->compareTo($line->quantity) === 0 ? $fullBase->minus((string) DB::table('receipt_lines')->where('purchase_line_id', $line->id)->sum('base_cost')) : $fullBase->multipliedBy($q)->dividedBy($line->quantity, 8, RoundingMode::HALF_UP);
                $extra = Posting::decimal('0');
                $detail = [];
                foreach ($allocations as $costId => $values) {
                    $prior = Posting::decimal('0');
                    foreach (DB::table('receipt_lines')->where('purchase_line_id', $line->id)->get() as $priorLine) {
                        $prior = $prior->plus(json_decode($priorLine->allocations, true)[$costId] ?? '0');
                    }
                    $share = $q->plus($line->received_quantity)->compareTo($line->quantity) === 0 ? Posting::decimal($values[$line->id])->minus($prior) : Posting::decimal($values[$line->id])->multipliedBy($q)->dividedBy($line->quantity, 8, RoundingMode::HALF_UP);
                    $detail[$costId] = (string) $share;
                    $extra = $extra->plus($share);
                }
                $value = $base->plus($extra)->toScale(8, RoundingMode::HALF_UP);
                DB::table('receipt_lines')->insert(['purchase_receipt_id' => $receipt, 'purchase_line_id' => $line->id, 'product_id' => $line->product_id, 'quantity' => (string) $q, 'base_cost' => (string) $base, 'additional_cost' => (string) $extra, 'landed_value' => (string) $value, 'allocations' => json_encode($detail, JSON_THROW_ON_ERROR)]);
                $cost = $this->stock->move($line->product_id, (string) $q, (string) $value, 'sellable', 'receipt', 'receipt', $receipt, 'Pranim i konfirmuar '.$data['reference'], $data['received_on']);
                $ledger = $cost->ledgerMovementValue();
                $journal[] = ['account' => '1200', 'debit' => $ledger, 'product_id' => $line->product_id];
                $journal[] = ['account' => '2150', 'credit' => $ledger, 'product_id' => $line->product_id];
                DB::table('purchase_lines')->where('id', $line->id)->update(['received_quantity' => (string) $q->plus($line->received_quantity)]);
            }
            if ($journal && collect($journal)->contains(fn ($l) => Posting::decimal($l['debit'] ?? $l['credit'])->isPositive())) {
                $this->posting->journal('receipt', $receipt, $data['received_on'], 'Pranim me kosto të zbarkuar', $journal);
            }
            $remaining = DB::table('purchase_lines')->where('purchase_order_id', $orderId)->whereColumn('received_quantity', '<', 'quantity')->exists();
            DB::table('purchase_orders')->where('id', $orderId)->update(['status' => $remaining ? 'partially_received' : 'received', 'arrived_on' => $data['received_on'], 'updated_at' => now()]);

            return ['id' => $receipt];
        });
    }

    public function cost(int $orderId, array $data): int
    {
        return DB::transaction(function () use ($orderId, $data) {
            $percent = Posting::decimal($data['recoverable_percent']);
            if ($percent->compareTo('100') > 0 || ($percent->isPositive() && ! ($this->posting->configured()['vat_registered'] ?? false))) {
                throw new \DomainException('Rikuperimi i TVSH kërkon normë deri 100% dhe regjistrim të konfirmuar.');
            }
            $order = DB::table('purchase_orders')->where('id', $orderId)->lockForUpdate()->firstOrFail();
            if ($order->status !== 'draft') {
                throw new \DomainException('Kostot finalizohen para pranimit; ndryshimet pas pranimit kërkojnë korrigjim të kontrolluar.');
            }
            $id = DB::table('landed_cost_components')->insertGetId(['purchase_order_id' => $orderId, ...$data, 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('purchase.cost_added','purchase',$orderId,['component_id' => $id]);

            return $id;
        });
    }
}
