<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Shared\DomainViolation;
use App\Domain\Shared\ExactDecimal;
use Brick\Math\RoundingMode;

/** The orchestration service must lock the persisted cost state before invoking these calculations. */
final class WeightedAverageCosting
{
    public function receive(InventoryCostState $state, Quantity $quantity, mixed $landedValue): CostingResult
    {
        $quantity->assertPositive();
        $value = ExactDecimal::parse($landedValue, 8, 24);
        if ($value->isNegative()) {
            throw new DomainViolation('Receipt landed value cannot be negative.');
        }

        $next = new InventoryCostState(
            $state->quantity->add($quantity)->amount(),
            (string) $state->value->plus($value),
        );

        return new CostingResult($state, $next, $quantity->amount(), (string) $value);
    }

    public function dispatch(InventoryCostState $state, Quantity $quantity): CostingResult
    {
        $quantity->assertPositive();
        if ($quantity->compare($state->quantity) > 0) {
            throw new DomainViolation('Dispatch exceeds the available inventory quantity.');
        }

        // Use the total-value ratio rather than a previously rounded display average.
        $cost = $quantity->compare($state->quantity) === 0
            ? $state->value
            : $state->value->multipliedBy($quantity->decimal())
                ->dividedBy($state->quantity->decimal(), 8, RoundingMode::HALF_UP);

        $next = new InventoryCostState(
            $state->quantity->subtract($quantity)->amount(),
            (string) $state->value->minus($cost),
        );

        return new CostingResult($state, $next, (string) $quantity->decimal()->negated(), (string) $cost->negated());
    }

    /** Source value must come from the original dispatch/remaining-return allocation, not today's average. */
    public function returnAtSourceCost(InventoryCostState $state, Quantity $quantity, mixed $sourceValue): CostingResult
    {
        return $this->receive($state, $quantity, $sourceValue);
    }
}
