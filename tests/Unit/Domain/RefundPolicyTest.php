<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Finance\Money;
use App\Domain\Inventory\Quantity;
use App\Domain\Sales\RefundPolicy;
use App\Domain\Shared\DomainViolation;
use PHPUnit\Framework\TestCase;

final class RefundPolicyTest extends TestCase
{
    public function test_partial_line_credits_clear_the_original_discounted_total_exactly(): void
    {
        $policy = new RefundPolicy;
        $refunded = Money::zero();
        $returned = Quantity::zero();
        $allocations = [];
        for ($index = 0; $index < 3; $index++) {
            $amount = $policy->allocateRefund(Money::of('10.00'), Quantity::of('3'), $refunded, $returned, Quantity::of('1'));
            $allocations[] = $amount->amount();
            $refunded = $refunded->add($amount);
            $returned = $returned->add(Quantity::of('1'));
        }
        self::assertSame(['3.33', '3.33', '3.34'], $allocations);
        self::assertSame('10.00', $refunded->amount());
    }

    public function test_partial_source_cost_returns_clear_value8_residuals_exactly(): void
    {
        $policy = new RefundPolicy;
        $first = $policy->allocateSourceValue(Quantity::of('3'), '1', Quantity::zero(), '0', Quantity::of('1'));
        $second = $policy->allocateSourceValue(Quantity::of('3'), '1', Quantity::of('1'), $first, Quantity::of('1'));
        $third = $policy->allocateSourceValue(Quantity::of('3'), '1', Quantity::of('2'), '0.66666666', Quantity::of('1'));
        self::assertSame('0.33333333', $first);
        self::assertSame('0.33333333', $second);
        self::assertSame('0.33333334', $third);
    }

    public function test_refund_without_physical_return_is_allowed_within_the_money_limit(): void
    {
        (new RefundPolicy)->assertRefundWithin(Money::of('10'), Money::of('2'), Money::of('8'));
        self::assertTrue(true);
    }

    public function test_refund_replay_cannot_exceed_remaining_money(): void
    {
        $this->expectException(DomainViolation::class);
        (new RefundPolicy)->assertRefundWithin(Money::of('10'), Money::of('10'), Money::of('1'));
    }

    public function test_return_replay_cannot_exceed_original_dispatched_quantity(): void
    {
        $this->expectException(DomainViolation::class);
        (new RefundPolicy)->assertReturnWithin(Quantity::of('2'), Quantity::of('2'), Quantity::of('1'));
    }

    public function test_cost_without_matching_previous_return_quantity_is_rejected(): void
    {
        $this->expectException(DomainViolation::class);
        (new RefundPolicy)->allocateSourceValue(Quantity::of('2'), '10', Quantity::zero(), '5', Quantity::of('1'));
    }
}
