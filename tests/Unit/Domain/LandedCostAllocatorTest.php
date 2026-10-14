<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Finance\Money;
use App\Domain\Purchasing\LandedCostAllocator;
use App\Domain\Shared\DomainViolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LandedCostAllocatorTest extends TestCase
{
    public function test_largest_remainder_conserves_cost_and_uses_stable_line_ids_for_ties(): void
    {
        $allocator = new LandedCostAllocator;
        $first = $allocator->allocate(Money::of('0.01'), ['b' => '1', 'a' => '1', 'c' => '1']);
        $second = $allocator->allocate(Money::of('0.01'), ['c' => '1', 'b' => '1', 'a' => '1']);
        self::assertSame(['a' => '0.01', 'b' => '0.00', 'c' => '0.00'], $this->amounts($first));
        self::assertSame($this->amounts($first), $this->amounts($second));
    }

    public function test_zero_weight_line_gets_no_cost_and_nonuniform_basis_is_exact(): void
    {
        $result = (new LandedCostAllocator)->allocate(Money::of('10.00'), ['a' => '1', 'b' => '2', 'c' => '0']);
        self::assertSame(['a' => '3.33', 'b' => '6.67', 'c' => '0.00'], $this->amounts($result));
    }

    public function test_very_large_amount_allocation_does_not_use_native_integer_cents(): void
    {
        $total = Money::of('99999999999999999.99');
        $parts = (new LandedCostAllocator)->allocate($total, ['one' => '1', 'two' => '1']);
        self::assertSame($total->amount(), $parts['one']->add($parts['two'])->amount());
    }

    #[DataProvider('invalidBases')]
    public function test_missing_negative_or_zero_basis_is_rejected(array $weights): void
    {
        $this->expectException(DomainViolation::class);
        (new LandedCostAllocator)->allocate(Money::of('10.00'), $weights);
    }

    public static function invalidBases(): iterable
    {
        yield 'missing lines' => [[]];
        yield 'zero denominator' => [['a' => '0', 'b' => '0']];
        yield 'negative weight' => [['a' => '-1', 'b' => '2']];
        yield 'float weight' => [['a' => 0.5]];
    }

    #[DataProvider('vatShares')]
    public function test_vat_recoverability_is_configurable_and_conserves_amount(string $percent, string $recoverable, string $nonrecoverable): void
    {
        $split = (new LandedCostAllocator)->splitVat(Money::of('18.00'), $percent);
        self::assertSame($recoverable, $split->recoverable->amount());
        self::assertSame($nonrecoverable, $split->nonrecoverable->amount());
        self::assertSame('18.00', $split->recoverable->add($split->nonrecoverable)->amount());
    }

    public static function vatShares(): iterable
    {
        yield 'fully recoverable excluded from landed cost' => ['100', '18.00', '0.00'];
        yield 'not recoverable' => ['0', '0.00', '18.00'];
        yield 'partially recoverable' => ['65.5', '11.79', '6.21'];
    }

    public function test_invalid_vat_recoverability_is_rejected(): void
    {
        $this->expectException(DomainViolation::class);
        (new LandedCostAllocator)->splitVat(Money::of('18.00'), '100.000001');
    }

    private function amounts(array $parts): array
    {
        return array_map(static fn (Money $amount): string => $amount->amount(), $parts);
    }
}
