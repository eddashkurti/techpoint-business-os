<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Domain\Finance\Money;
use App\Domain\Shared\DomainViolation;

final readonly class JournalLine
{
    public function __construct(public string $account, public Money $debit, public Money $credit)
    {
        $debit->assertCompatible($credit);
        if ($account === '' || trim($account) !== $account) {
            throw new DomainViolation('A journal line requires a valid account identifier.');
        }
        if ($debit->isNegative() || $credit->isNegative() || $debit->isZero() === $credit->isZero()) {
            throw new DomainViolation('A journal line must contain exactly one positive debit or credit.');
        }
    }

    public static function fromAmounts(string $account, mixed $debit, mixed $credit, string $currency = 'EUR'): self
    {
        return new self($account, Money::of($debit, $currency), Money::of($credit, $currency));
    }
}
