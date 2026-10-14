<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use App\Domain\Shared\DomainViolation;
use App\Domain\Shared\ExactDecimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final readonly class Money
{
    private function __construct(private BigDecimal $value, public string $currency, public int $minorUnits) {}

    public static function of(mixed $amount, string $currency = 'EUR', int $minorUnits = 2): self
    {
        self::assertCurrency($currency, $minorUnits);

        return new self(ExactDecimal::parse($amount, $minorUnits, 20), $currency, $minorUnits);
    }

    /** Computed monetary amounts are rounded explicitly, never at input parsing. */
    public static function rounded(BigDecimal $amount, string $currency = 'EUR', int $minorUnits = 2): self
    {
        self::assertCurrency($currency, $minorUnits);
        $rounded = $amount->toScale($minorUnits, RoundingMode::HALF_UP);

        return new self(ExactDecimal::assertFits($rounded, $minorUnits, 20), $currency, $minorUnits);
    }

    public static function zero(string $currency = 'EUR', int $minorUnits = 2): self
    {
        return self::of('0', $currency, $minorUnits);
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
        $this->assertCompatible($other);

        return self::of((string) $this->value->plus($other->value), $this->currency, $this->minorUnits);
    }

    public function subtract(self $other): self
    {
        $this->assertCompatible($other);

        return self::of((string) $this->value->minus($other->value), $this->currency, $this->minorUnits);
    }

    public function compare(self $other): int
    {
        $this->assertCompatible($other);

        return $this->value->compareTo($other->value);
    }

    public function isNegative(): bool
    {
        return $this->value->isNegative();
    }

    public function isZero(): bool
    {
        return $this->value->isZero();
    }

    public function convertTo(string $currency, mixed $rate, int $minorUnits = 2): self
    {
        $decimalRate = ExactDecimal::parse($rate, 12, 24);
        if (! $decimalRate->isPositive()) {
            throw new DomainViolation('An exchange rate must be positive.');
        }

        return self::rounded($this->value->multipliedBy($decimalRate), $currency, $minorUnits);
    }

    public function assertCompatible(self $other): void
    {
        if ($this->currency !== $other->currency || $this->minorUnits !== $other->minorUnits) {
            throw new DomainViolation('Money amounts must have the same currency and precision.');
        }
    }

    private static function assertCurrency(string $currency, int $minorUnits): void
    {
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1 || $minorUnits < 0 || $minorUnits > 6) {
            throw new DomainViolation('Invalid currency or minor-unit precision.');
        }
    }
}
