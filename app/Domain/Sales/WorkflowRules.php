<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Finance\Money;
use App\Domain\Inventory\Quantity;
use App\Domain\Shared\DomainViolation;

final class WorkflowRules
{
    private const array TRANSITIONS = [
        'order' => [
            'draft' => ['accepted', 'cancelled'],
            'accepted' => ['completed', 'cancelled'],
            'completed' => [],
            'cancelled' => [],
        ],
        'fulfillment' => [
            'unallocated' => ['reserved'],
            'reserved' => ['unallocated', 'partially_dispatched', 'dispatched'],
            'partially_dispatched' => ['dispatched', 'partially_returned', 'returned'],
            'dispatched' => ['partially_returned', 'returned'],
            'partially_returned' => ['returned'],
            'returned' => [],
        ],
        'delivery' => [
            'pending' => ['in_transit', 'cancelled'],
            'in_transit' => ['delivered', 'failed', 'returning'],
            'failed' => ['in_transit', 'returning'],
            'delivered' => ['returning'],
            'returning' => ['returned'],
            'returned' => [],
            'cancelled' => [],
        ],
        'fiscal' => [
            'not_required' => [],
            'pending' => ['queued'],
            'queued' => ['submitting', 'rejected'],
            'submitting' => ['issued', 'rejected', 'outcome_unknown'],
            'outcome_unknown' => ['issued', 'rejected', 'intervention_required'],
            'issued' => [],
            'rejected' => [],
            'intervention_required' => [],
        ],
    ];

    /** An unchanged state is idempotent; posting-effect duplication is guarded by orchestration keys. */
    public function assertTransition(string $dimension, string $from, string $to): void
    {
        $states = self::TRANSITIONS[$dimension] ?? null;
        if ($states === null || ! array_key_exists($from, $states) || ! array_key_exists($to, $states)) {
            throw new DomainViolation('Unknown workflow dimension or state.');
        }
        if ($from !== $to && ! in_array($to, $states[$from], true)) {
            throw new DomainViolation('This workflow transition is not permitted.');
        }
    }

    public function assertSaleCompletion(string $fulfillmentState, bool $fiscalRequired, string $fiscalState): void
    {
        if ($fulfillmentState !== 'dispatched') {
            throw new DomainViolation('Sale completion requires its fulfilled quantities to be dispatched.');
        }
        if ($fiscalRequired && $fiscalState !== 'issued') {
            throw new DomainViolation('Required fiscal issuance must be confirmed before sale completion.');
        }
        if (! $fiscalRequired && ! in_array($fiscalState, ['not_required', 'issued'], true)) {
            throw new DomainViolation('An unresolved fiscal request cannot be bypassed through sale completion.');
        }
    }

    /** The provider capability/evidence must come from its verified contract, not an operator checkbox. */
    public function assertFiscalRetry(string $state, bool $providerIdempotency, bool $confirmedNotIssued): void
    {
        if (! array_key_exists($state, self::TRANSITIONS['fiscal']) || in_array($state, ['issued', 'not_required', 'rejected'], true)) {
            throw new DomainViolation('This fiscal request cannot be submitted again.');
        }
        if (in_array($state, ['submitting', 'outcome_unknown', 'intervention_required'], true) && ! $providerIdempotency && ! $confirmedNotIssued) {
            throw new DomainViolation('Reconcile the unknown issuance outcome before attempting a non-idempotent resend.');
        }
    }

    public function assertRestock(Quantity $received, Quantity $eligible, bool $inspected): void
    {
        $received->assertPositive();
        $eligible->assertNonnegative();
        if (! $inspected || $received->compare($eligible) > 0) {
            throw new DomainViolation('Restocking needs inspection and an eligible physically received quantity.');
        }
    }

    public function paymentStatus(Money $invoiceAmount, Money $collected, Money $refunded): string
    {
        $invoiceAmount->assertCompatible($collected);
        $collected->assertCompatible($refunded);
        if ($invoiceAmount->isNegative() || $collected->isNegative() || $refunded->isNegative() || $refunded->compare($collected) > 0) {
            throw new DomainViolation('Invalid payment or refund totals.');
        }
        if (! $refunded->isZero()) {
            return $refunded->compare($collected) === 0 ? 'refunded' : 'partially_refunded';
        }
        if ($collected->isZero()) {
            return $invoiceAmount->isZero() ? 'paid' : 'unpaid';
        }

        return $collected->compare($invoiceAmount) >= 0 ? 'paid' : 'partially_paid';
    }
}
