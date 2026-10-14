<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Accounting\JournalLine;
use App\Domain\Accounting\JournalValidator;
use App\Domain\Shared\DomainViolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JournalValidatorTest extends TestCase
{
    public function test_multi_line_journal_balances_exactly_and_reversal_preserves_original(): void
    {
        $lines = [
            JournalLine::fromAmounts('cash', '118.00', '0'),
            JournalLine::fromAmounts('sales', '0', '100.00'),
            JournalLine::fromAmounts('vat', '0', '18.00'),
        ];
        $validator = new JournalValidator;
        $totals = $validator->validate($lines);
        self::assertSame('118.00', $totals->debits->amount());
        self::assertSame('118.00', $totals->credits->amount());
        $reversal = $validator->reversal($lines);
        self::assertSame('118.00', $validator->validate($reversal)->credits->amount());
        self::assertSame('118.00', $lines[0]->debit->amount());
        self::assertSame('0.00', $reversal[0]->debit->amount());
    }

    public function test_one_cent_imbalance_is_rejected(): void
    {
        $this->expectException(DomainViolation::class);
        (new JournalValidator)->validate([
            JournalLine::fromAmounts('cash', '10.00', '0'),
            JournalLine::fromAmounts('sales', '0', '9.99'),
        ]);
    }

    #[DataProvider('invalidLines')]
    public function test_invalid_journal_line_is_rejected(string $debit, string $credit): void
    {
        $this->expectException(DomainViolation::class);
        JournalLine::fromAmounts('cash', $debit, $credit);
    }

    public static function invalidLines(): iterable
    {
        yield 'both zero' => ['0', '0'];
        yield 'both positive' => ['1', '1'];
        yield 'negative debit' => ['-1', '0'];
        yield 'negative credit' => ['0', '-1'];
    }

    public function test_foreign_currency_cannot_be_posted_as_eur_base_lines(): void
    {
        $this->expectException(DomainViolation::class);
        (new JournalValidator)->validate([
            JournalLine::fromAmounts('cash', '10', '0', 'USD'),
            JournalLine::fromAmounts('sales', '0', '10', 'USD'),
        ]);
    }

    public function test_empty_journal_is_rejected(): void
    {
        $this->expectException(DomainViolation::class);
        (new JournalValidator)->validate([]);
    }
}
