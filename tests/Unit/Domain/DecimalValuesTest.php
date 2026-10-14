<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Finance\Money;
use App\Domain\Inventory\Quantity;
use App\Domain\Shared\DomainViolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DecimalValuesTest extends TestCase
{
    public function test_money_arithmetic_preserves_values_beyond_native_float_precision(): void
    {
        $sum = Money::of('99999999999999999.99')->add(Money::of('0.01'));
        self::assertSame('100000000000000000.00', $sum->amount());
        self::assertSame('0.30', Money::of('0.10')->add(Money::of('0.20'))->amount());
        self::assertSame('-0.10', Money::of('0.10')->subtract(Money::of('0.20'))->amount());
    }

    #[DataProvider('invalidAmounts')]
    public function test_input_requires_an_exact_plain_decimal_string(mixed $input): void
    {
        $this->expectException(DomainViolation::class);
        Money::of($input);
    }

    public static function invalidAmounts(): iterable
    {
        yield 'float' => [0.1];
        yield 'integer' => [1];
        yield 'exponent' => ['1e2'];
        yield 'comma' => ['1,20'];
        yield 'whitespace' => [' 1.20'];
        yield 'silent rounding' => ['1.001'];
        yield 'oversize' => ['1000000000000000000.00'];
        yield 'nan' => ['NaN'];
    }

    public function test_currency_conversion_uses_an_explicit_exact_rate_and_rounding(): void
    {
        self::assertSame('0.40', Money::of('0.30', 'USD')->convertTo('EUR', '1.333333333333')->amount());
        self::assertSame('EUR', Money::of('10.00', 'USD')->convertTo('EUR', '0.91')->currency);
    }

    public function test_arithmetic_rejects_mixed_currency(): void
    {
        $this->expectException(DomainViolation::class);
        Money::of('1.00', 'USD')->add(Money::of('1.00', 'EUR'));
    }

    public function test_arithmetic_cannot_overflow_the_storage_precision(): void
    {
        $this->expectException(DomainViolation::class);
        Money::of('999999999999999999.99')->add(Money::of('0.01'));
    }

    public function test_nonpositive_exchange_rate_is_rejected(): void
    {
        $this->expectException(DomainViolation::class);
        Money::of('10.00')->convertTo('USD', '0');
    }

    public function test_quantity_supports_exact_fractional_units(): void
    {
        self::assertSame('0.000003', Quantity::of('0.000001')->add(Quantity::of('0.000002'))->amount());
        Quantity::of('2.000000')->assertWholeUnits();
        self::assertSame('2.000000', Quantity::of('2')->amount());
    }

    public function test_indivisible_product_rejects_fractional_units(): void
    {
        $this->expectException(DomainViolation::class);
        Quantity::of('0.500000')->assertWholeUnits();
    }
}
