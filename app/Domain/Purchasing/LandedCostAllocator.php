<?php

declare(strict_types=1);

namespace App\Domain\Purchasing;

use App\Domain\Finance\Money;
use App\Domain\Shared\DomainViolation;
use App\Domain\Shared\ExactDecimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class LandedCostAllocator
{
    /** @param array<string|int, string> $weights @return array<string|int, Money> */
    public function allocate(Money $total, array $weights): array
    {
        if ($total->isNegative() || $weights === []) {
            throw new DomainViolation('Allocation needs a nonnegative total and eligible lines.');
        }

        $weightTotal = BigDecimal::of('0');
        $values = [];
        foreach ($weights as $key => $weight) {
            if ((string) $key === '') {
                throw new DomainViolation('An allocation line needs an identifier.');
            }
            $value = ExactDecimal::parse($weight, 12, 30);
            if ($value->isNegative()) {
                throw new DomainViolation('Allocation weights cannot be negative.');
            }
            $values[$key] = $value;
            $weightTotal = $weightTotal->plus($value);
        }
        if (! $weightTotal->isPositive()) {
            throw new DomainViolation('Allocation basis cannot be zero.');
        }

        $factor = BigDecimal::of('1'.str_repeat('0', $total->minorUnits));
        $minorTotal = $total->decimal()->multipliedBy($factor)->toScale(0);
        $allocated = BigDecimal::of('0');
        $rows = [];
        foreach ($values as $key => $weight) {
            $numerator = $minorTotal->multipliedBy($weight);
            $units = $numerator->dividedBy($weightTotal, 0, RoundingMode::DOWN);
            $rows[] = [
                'key' => $key,
                'units' => $units,
                'remainder' => $numerator->minus($units->multipliedBy($weightTotal)),
            ];
            $allocated = $allocated->plus($units);
        }

        usort($rows, static function (array $left, array $right): int {
            $remainderOrder = $right['remainder']->compareTo($left['remainder']);

            return $remainderOrder !== 0 ? $remainderOrder : strcmp((string) $left['key'], (string) $right['key']);
        });

        // The largest-remainder deficit is always smaller than the number of eligible lines.
        $remaining = $minorTotal->minus($allocated)->toBigInteger()->toInt();
        $result = [];
        foreach ($rows as $index => $row) {
            $units = $index < $remaining ? $row['units']->plus('1') : $row['units'];
            $result[$row['key']] = Money::of(
                (string) $units->dividedBy($factor, $total->minorUnits, RoundingMode::UNNECESSARY),
                $total->currency,
                $total->minorUnits,
            );
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /** Recoverability is an approved percentage, not a tax-law assumption. */
    public function splitVat(Money $vat, mixed $recoverabilityPercent): VatAllocation
    {
        $percent = ExactDecimal::parse($recoverabilityPercent, 6, 12);
        if ($vat->isNegative() || $percent->isNegative() || $percent->compareTo('100') > 0) {
            throw new DomainViolation('VAT must be nonnegative and recoverability between 0 and 100 percent.');
        }

        $recoverable = Money::rounded(
            $vat->decimal()->multipliedBy($percent)->dividedBy('100', $vat->minorUnits, RoundingMode::HALF_UP),
            $vat->currency,
            $vat->minorUnits,
        );

        return new VatAllocation($recoverable, $vat->subtract($recoverable));
    }
}
