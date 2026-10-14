<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Finance\Money;
use App\Domain\Inventory\InventoryCostState;
use App\Domain\Inventory\Quantity;
use App\Domain\Inventory\WeightedAverageCosting;
use App\Domain\Shared\DomainViolation;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

final class WeightedAverageCostingTest extends TestCase
{
    public function test_receipt_and_dispatch_use_weighted_average_and_signed_movements(): void
    {
        $costing = new WeightedAverageCosting;
        $receipt = $costing->receive(new InventoryCostState('10', '20'), Quantity::of('10'), '40');
        self::assertSame('20.000000', $receipt->current->quantity->amount());
        self::assertSame('60.00000000', $receipt->current->valueAmount());
        self::assertSame('3.00000000', $receipt->current->averageCost());

        $sale = $costing->dispatch($receipt->current, Quantity::of('7'));
        self::assertSame('-7.000000', $sale->movementQuantity);
        self::assertSame('-21.00000000', $sale->movementValue);
        self::assertSame('13.000000', $sale->current->quantity->amount());
        self::assertSame('39.00000000', $sale->current->valueAmount());
    }

    public function test_last_dispatch_clears_all_rounding_residuals(): void
    {
        $costing = new WeightedAverageCosting;
        $state = new InventoryCostState('3', '1');
        $removed = BigDecimal::of('0');
        $ledgerRemoved = Money::zero();
        $ledgerMovements = [];
        for ($index = 0; $index < 3; $index++) {
            $result = $costing->dispatch($state, Quantity::of('1'));
            $removed = $removed->minus($result->movementValue);
            $ledgerRemoved = $ledgerRemoved->subtract(Money::of($result->ledgerMovementValue()));
            $ledgerMovements[] = $result->ledgerMovementValue();
            $state = $result->current;
        }
        self::assertSame('1.00000000', (string) $removed);
        self::assertSame(['-0.33', '-0.34', '-0.33'], $ledgerMovements);
        self::assertSame('1.00', $ledgerRemoved->amount());
        self::assertSame('0.000000', $state->quantity->amount());
        self::assertSame('0.00000000', $state->valueAmount());
        self::assertSame('0.00000000', $state->averageCost());
    }

    public function test_return_enters_at_original_source_value_instead_of_current_cost(): void
    {
        $result = (new WeightedAverageCosting)->returnAtSourceCost(new InventoryCostState('10', '40'), Quantity::of('2'), '4');
        self::assertSame('44.00000000', $result->current->valueAmount());
        self::assertSame('3.66666667', $result->current->averageCost());
    }

    public function test_dispatch_cannot_create_negative_stock(): void
    {
        $this->expectException(DomainViolation::class);
        (new WeightedAverageCosting)->dispatch(new InventoryCostState('1', '2'), Quantity::of('2'));
    }

    public function test_zero_quantity_cannot_retain_inventory_value(): void
    {
        $this->expectException(DomainViolation::class);
        new InventoryCostState('0', '0.00000001');
    }

    public function test_zero_cost_goods_are_allowed_but_zero_receipt_quantity_is_not(): void
    {
        $result = (new WeightedAverageCosting)->receive(new InventoryCostState('0', '0'), Quantity::of('1'), '0');
        self::assertSame('0.00000000', $result->current->valueAmount());
        $this->expectException(DomainViolation::class);
        (new WeightedAverageCosting)->receive($result->current, Quantity::zero(), '1');
    }

    public function test_receipt_does_not_mutate_previous_state(): void
    {
        $original = new InventoryCostState('1', '2');
        (new WeightedAverageCosting)->receive($original, Quantity::of('1'), '4');
        self::assertSame('1.000000', $original->quantity->amount());
        self::assertSame('2.00000000', $original->valueAmount());
    }
}
