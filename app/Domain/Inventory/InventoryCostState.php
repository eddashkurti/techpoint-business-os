<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Shared\DomainViolation;
use App\Domain\Shared\ExactDecimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final readonly class InventoryCostState
{
    public Quantity $quantity;

    public BigDecimal $value;

    public function __construct(mixed $quantity, mixed $value)
    {
        $this->quantity = Quantity::of($quantity);
        $this->quantity->assertNonnegative();
        $this->value = ExactDecimal::parse($value, 8, 24);
        if ($this->value->isNegative() || ($this->quantity->decimal()->isZero() && ! $this->value->isZero())) {
            throw new DomainViolation('Inventory value must be nonnegative and zero when quantity is zero.');
        }
    }

    public function averageCost(): string
    {
        if ($this->quantity->decimal()->isZero()) {
            return '0.00000000';
        }

        return (string) $this->value->dividedBy($this->quantity->decimal(), 8, RoundingMode::HALF_UP);
    }

    public function valueAmount(): string
    {
        return (string) $this->value;
    }
}
