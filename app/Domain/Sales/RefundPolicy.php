<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Finance\Money;
use App\Domain\Inventory\Quantity;
use App\Domain\Shared\DomainViolation;
use App\Domain\Shared\ExactDecimal;
use Brick\Math\RoundingMode;

final class RefundPolicy
{
    public function assertRefundWithin(Money $originalRefundable, Money $alreadyRefunded, Money $requested): void
    {
        $originalRefundable->assertCompatible($alreadyRefunded);
        $originalRefundable->assertCompatible($requested);
        if ($originalRefundable->isNegative() || $alreadyRefunded->isNegative() || $requested->isNegative() || $requested->isZero()) {
            throw new DomainViolation('Refund amounts must be positive and recorded totals nonnegative.');
        }
        if ($alreadyRefunded->compare($originalRefundable) > 0 || $requested->compare($originalRefundable->subtract($alreadyRefunded)) > 0) {
            throw new DomainViolation('Refund exceeds the remaining refundable amount.');
        }
    }

    public function assertReturnWithin(Quantity $original, Quantity $alreadyReturned, Quantity $requested): void
    {
        $original->assertPositive();
        $alreadyReturned->assertNonnegative();
        $requested->assertPositive();
        if ($alreadyReturned->compare($original) > 0 || $requested->compare($original->subtract($alreadyReturned)) > 0) {
            throw new DomainViolation('Return exceeds the remaining eligible quantity.');
        }
    }

    /** Proportion of the original discounted line total; the last partial reversal clears its remainder. */
    public function allocateRefund(
        Money $originalAmount,
        Quantity $originalQuantity,
        Money $alreadyRefunded,
        Quantity $alreadyReturned,
        Quantity $requested,
    ): Money {
        $this->assertReturnWithin($originalQuantity, $alreadyReturned, $requested);
        $originalAmount->assertCompatible($alreadyRefunded);
        if ($originalAmount->isNegative() || $alreadyRefunded->isNegative() || $alreadyRefunded->compare($originalAmount) > 0) {
            throw new DomainViolation('Invalid original or cumulative refund amount.');
        }
        if ($alreadyReturned->decimal()->isZero() && ! $alreadyRefunded->isZero()) {
            throw new DomainViolation('Quantity-based credit allocation requires matching prior return facts.');
        }

        $remaining = $originalAmount->subtract($alreadyRefunded);
        if ($requested->compare($originalQuantity->subtract($alreadyReturned)) === 0) {
            return $remaining;
        }
        $allocated = Money::rounded(
            $originalAmount->decimal()->multipliedBy($requested->decimal())
                ->dividedBy($originalQuantity->decimal(), $originalAmount->minorUnits, RoundingMode::HALF_UP),
            $originalAmount->currency,
            $originalAmount->minorUnits,
        );

        return $allocated->compare($remaining) > 0 ? $remaining : $allocated;
    }

    /** Original dispatch value at scale8, independent of the current moving average or money refund. */
    public function allocateSourceValue(
        Quantity $originalQuantity,
        mixed $originalValue,
        Quantity $alreadyReturned,
        mixed $alreadyReturnedValue,
        Quantity $requested,
    ): string {
        $this->assertReturnWithin($originalQuantity, $alreadyReturned, $requested);
        $original = ExactDecimal::parse($originalValue, 8, 24);
        $prior = ExactDecimal::parse($alreadyReturnedValue, 8, 24);
        if ($original->isNegative() || $prior->isNegative() || $prior->compareTo($original) > 0) {
            throw new DomainViolation('Invalid original or cumulative return cost.');
        }
        if ($alreadyReturned->decimal()->isZero() && ! $prior->isZero()) {
            throw new DomainViolation('Return cost exists without a prior returned quantity.');
        }

        $remaining = $original->minus($prior);
        if ($requested->compare($originalQuantity->subtract($alreadyReturned)) === 0) {
            return (string) $remaining;
        }
        $allocated = $original->multipliedBy($requested->decimal())
            ->dividedBy($originalQuantity->decimal(), 8, RoundingMode::HALF_UP);

        return (string) ($allocated->compareTo($remaining) > 0 ? $remaining : $allocated);
    }
}
