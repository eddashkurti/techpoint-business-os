<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Inventory\CostingResult;
use App\Domain\Inventory\InventoryCostState;
use App\Domain\Inventory\Quantity;
use App\Domain\Inventory\WeightedAverageCosting;
use Illuminate\Support\Facades\DB;

final class Stock
{
    public function balance(int $productId, string $condition = 'sellable'): object
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Stock changes require a database transaction.');
        }
        DB::table('products')->where('id', $productId)->lockForUpdate()->firstOrFail();
        DB::table('stock_balances')->insertOrIgnore(['warehouse_id' => 1, 'product_id' => $productId, 'condition' => $condition, 'on_hand' => '0', 'reserved' => '0', 'value' => '0', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('stock_balances')->where('warehouse_id', 1)->where('product_id', $productId)->where('condition', $condition)->lockForUpdate()->first();
    }

    public function reserve(int $productId, string $quantity): void
    {
        $b = $this->balance($productId);
        $q = Quantity::of($quantity);
        $q->assertPositive();
        if (Posting::decimal($b->on_hand)->minus($b->reserved)->compareTo($quantity) < 0) {
            throw new \DomainException('Stoku i disponueshëm nuk mjafton.');
        }
        DB::table('stock_balances')->where('id', $b->id)->update(['reserved' => (string) Posting::decimal($b->reserved)->plus($quantity), 'updated_at' => now()]);
    }

    public function release(int $productId, string $quantity): void
    {
        $b = $this->balance($productId);
        if (Posting::decimal($quantity)->isNegative() || Posting::decimal($b->reserved)->compareTo($quantity) < 0) {
            throw new \DomainException('Rezervimi nuk përputhet.');
        }
        DB::table('stock_balances')->where('id', $b->id)->update(['reserved' => (string) Posting::decimal($b->reserved)->minus($quantity), 'updated_at' => now()]);
    }

    public function move(int $productId, string $quantity, ?string $receiptValue, string $condition, string $kind, string $source, string|int $sourceId, string $reason, string $date, bool $consumeReservation = false): CostingResult
    {
        if (! in_array($condition, ['sellable', 'quarantine', 'damaged'], true) || trim($reason) === '') {
            throw new \DomainException('Gjendja dhe arsyeja janë të detyrueshme.');
        }
        $b = $this->balance($productId, $condition);
        $state = new InventoryCostState($b->on_hand, $b->value);
        $q = Posting::decimal($quantity);
        if ($q->isZero()) {
            throw new \DomainException('Sasia nuk mund të jetë zero.');
        }
        if ($q->isPositive()) {
            $result = (new WeightedAverageCosting)->receive($state, Quantity::of((string) $q), $receiptValue ?? '0');
        } else {
            if ($condition === 'sellable' && ! $consumeReservation && Posting::decimal($b->on_hand)->minus($b->reserved)->compareTo($q->negated()) < 0) {
                throw new \DomainException('Nuk mund të ndryshoni stokun e rezervuar.');
            }
            if ($consumeReservation) {
                $this->release($productId, (string) $q->negated());
            }
            $result = (new WeightedAverageCosting)->dispatch($state, Quantity::of((string) $q->negated()));
        }
        DB::table('stock_balances')->where('id', $b->id)->update(['on_hand' => $result->current->quantity->amount(), 'value' => (string) $result->current->value, 'updated_at' => now()]);
        DB::table('stock_movements')->insert(['warehouse_id' => 1, 'product_id' => $productId, 'condition' => $condition, 'kind' => $kind, 'quantity' => $result->movementQuantity, 'value' => $result->movementValue, 'balance_quantity' => $result->current->quantity->amount(), 'balance_value' => (string) $result->current->value, 'source_type' => $source, 'source_id' => (string) $sourceId, 'reason' => $reason, 'user_id' => auth()->id(), 'occurred_at' => $date.' 12:00:00', 'created_at' => now()]);
        Audit::record('stock.'.$kind, 'product', $productId, ['source' => $source, 'source_id' => $sourceId, 'quantity' => $result->movementQuantity]);

        return $result;
    }
}
