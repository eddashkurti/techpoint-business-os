<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Access\SensitiveOperationPolicy;
use App\Domain\Finance\Money;
use App\Domain\Inventory\Quantity;
use App\Domain\Sales\WorkflowRules;
use App\Domain\Shared\DomainViolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkflowAndPermissionsTest extends TestCase
{
    public function test_order_and_delivery_transitions_are_independent_from_payment_projection(): void
    {
        $rules = new WorkflowRules;
        $rules->assertTransition('order', 'draft', 'accepted');
        $rules->assertTransition('delivery', 'in_transit', 'failed');
        self::assertSame('partially_paid', $rules->paymentStatus(Money::of('100'), Money::of('40'), Money::zero()));
        self::assertSame('partially_refunded', $rules->paymentStatus(Money::of('100'), Money::of('100'), Money::of('20')));
        self::assertSame('refunded', $rules->paymentStatus(Money::of('100'), Money::of('100'), Money::of('100')));
    }

    public function test_unknown_fiscal_outcome_cannot_be_retried_without_supported_guarantee(): void
    {
        $this->expectException(DomainViolation::class);
        (new WorkflowRules)->assertFiscalRetry('outcome_unknown', false, false);
    }

    public function test_supported_idempotency_or_verified_nonissuance_permits_recovery(): void
    {
        $rules = new WorkflowRules;
        $rules->assertFiscalRetry('outcome_unknown', true, false);
        $rules->assertFiscalRetry('intervention_required', false, true);
        $rules->assertTransition('fiscal', 'outcome_unknown', 'issued');
        self::assertTrue(true);
    }

    public function test_issued_fiscal_document_cannot_be_submitted_again(): void
    {
        $this->expectException(DomainViolation::class);
        (new WorkflowRules)->assertFiscalRetry('issued', true, true);
    }

    #[DataProvider('unresolvedFiscalStates')]
    public function test_sale_completion_cannot_bypass_fiscal_confirmation(bool $required, string $status): void
    {
        $this->expectException(DomainViolation::class);
        (new WorkflowRules)->assertSaleCompletion('dispatched', $required, $status);
    }

    public static function unresolvedFiscalStates(): iterable
    {
        yield 'queued is not issued' => [true, 'queued'];
        yield 'unknown is not issued' => [true, 'outcome_unknown'];
        yield 'false flag cannot bypass pending request' => [false, 'pending'];
    }

    public function test_confirmed_fiscal_sale_can_complete(): void
    {
        (new WorkflowRules)->assertSaleCompletion('dispatched', true, 'issued');
        (new WorkflowRules)->assertSaleCompletion('dispatched', false, 'not_required');
        self::assertTrue(true);
    }

    public function test_refund_or_failed_delivery_cannot_restock_without_physical_inspection(): void
    {
        $this->expectException(DomainViolation::class);
        (new WorkflowRules)->assertRestock(Quantity::of('1'), Quantity::of('1'), false);
    }

    public function test_accountant_export_permission_does_not_grant_journal_posting(): void
    {
        $policy = new SensitiveOperationPolicy;
        $grants = ['accounting.view', 'accounting.export', 'accounting.reconcile'];
        $policy->authorize('accounting.export', $grants);
        $this->expectException(DomainViolation::class);
        $policy->authorize('accounting.post', $grants);
    }

    public function test_role_name_or_wildcard_does_not_bypass_explicit_grants(): void
    {
        $this->expectException(DomainViolation::class);
        (new SensitiveOperationPolicy)->authorize('refund.issue', ['accountant', '*']);
    }

    public function test_closed_period_rejects_posting(): void
    {
        $this->expectException(DomainViolation::class);
        (new SensitiveOperationPolicy)->assertOpenPeriod(true);
    }

    public function test_correction_requires_a_reason(): void
    {
        $this->expectException(DomainViolation::class);
        (new SensitiveOperationPolicy)->assertCorrectionReason('  ');
    }

    public function test_cancelled_order_cannot_be_silently_reaccepted(): void
    {
        $this->expectException(DomainViolation::class);
        (new WorkflowRules)->assertTransition('order', 'cancelled', 'accepted');
    }
}
