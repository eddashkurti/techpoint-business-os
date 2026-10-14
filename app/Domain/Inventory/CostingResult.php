<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Finance\Money;

final readonly class CostingResult
{
    public function __construct(
        public InventoryCostState $previous,
        public InventoryCostState $current,
        public string $movementQuantity,
        public string $movementValue,
    ) {}

    /** Reconcile the scale2 inventory control account to the rounded scale8 cost state cumulatively. */
    public function ledgerMovementValue(): string
    {
        return Money::rounded($this->current->value)
            ->subtract(Money::rounded($this->previous->value))
            ->amount();
    }
}
