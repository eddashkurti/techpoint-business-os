<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Domain\Finance\Money;
use App\Domain\Shared\DomainViolation;

final class JournalValidator
{
    /** Validate base-currency postings; original-currency metadata is retained by orchestration. @param array<JournalLine> $lines */
    public function validate(array $lines, string $baseCurrency = 'EUR'): JournalTotals
    {
        if (count($lines) < 2) {
            throw new DomainViolation('A journal requires at least two posting lines.');
        }

        $debits = Money::zero($baseCurrency);
        $credits = Money::zero($baseCurrency);
        foreach ($lines as $line) {
            if (! $line instanceof JournalLine) {
                throw new DomainViolation('All journal lines must be validated posting values.');
            }
            $debits = $debits->add($line->debit);
            $credits = $credits->add($line->credit);
        }

        if ($debits->isZero() || $debits->compare($credits) !== 0) {
            throw new DomainViolation('Journal debits and credits must balance exactly.');
        }

        return new JournalTotals($debits, $credits);
    }

    /** Reversals create new lines; they never mutate the original journal. @param array<JournalLine> $lines @return array<JournalLine> */
    public function reversal(array $lines, string $baseCurrency = 'EUR'): array
    {
        $this->validate($lines, $baseCurrency);

        return array_map(static fn (JournalLine $line): JournalLine => new JournalLine($line->account, $line->credit, $line->debit), $lines);
    }
}
