<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Shared\DomainViolation;
use App\Domain\Shared\ExactDecimal;
use Brick\Math\BigDecimal;

final readonly class Quantity
{
    private function __construct(private BigDecimal $value) {}

    public static function of(mixed $quantity): self
    {
        return new self(ExactDecimal::parse($quantity, 6, 20));
    }

    public static function zero(): self
    {
        return self::of('0');
    }

    public function amount(): string
    {
        return (string) $this->value;
    }

    public function decimal(): BigDecimal
    {
        return $this->value;
    }

    public function add(self $other): self
    {
        return self::of((string) $this->value->plus($other->value));
    }

    public function subtract(self $other): self
    {
        return self::of((string) $this->value->minus($other->value));
    }

    public function compare(self $other): int
    {
        return $this->value->compareTo($other->value);
    }

    public function assertPositive(): void
    {
        if (! $this->value->isPositive()) {
            throw new DomainViolation('Quantity must be positive.');
        }
    }

    public function assertNonnegative(): void
    {
        if ($this->value->isNegative()) {
            throw new DomainViolation('Quantity cannot be negative.');
        }
    }

    public function assertWholeUnits(): void
    {
        if ($this->value->strippedOfTrailingZeros()->getScale() > 0) {
            throw new DomainViolation('This product requires whole base units.');
        }
    }
}
