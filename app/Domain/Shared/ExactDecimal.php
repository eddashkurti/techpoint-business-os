<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class ExactDecimal
{
    /** Reject floats even when the caller has PHP's weak scalar typing enabled. */
    public static function parse(mixed $input, int $scale, int $precision = 24): BigDecimal
    {
        if (! is_string($input) || preg_match('/^-?\d+(?:\.\d+)?$/D', $input) !== 1) {
            throw new DomainViolation('Amounts must be plain decimal strings.');
        }

        if ($scale < 0 || $precision <= $scale) {
            throw new DomainViolation('Invalid decimal precision.');
        }

        try {
            $value = BigDecimal::of($input)->toScale($scale, RoundingMode::UNNECESSARY);
        } catch (\Throwable $exception) {
            throw new DomainViolation('The amount exceeds the allowed decimal scale.', 0, $exception);
        }

        return self::assertFits($value, $scale, $precision);
    }

    public static function assertFits(BigDecimal $value, int $scale, int $precision): BigDecimal
    {
        $normalized = $value->toScale($scale, RoundingMode::UNNECESSARY);
        $integer = explode('.', (string) $normalized->abs())[0];
        if (strlen(ltrim($integer, '0')) > $precision - $scale) {
            throw new DomainViolation('The amount exceeds the allowed decimal precision.');
        }

        return $normalized;
    }
}
