<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Domain\Finance\Money;

final readonly class JournalTotals
{
    public function __construct(public Money $debits, public Money $credits) {}
}
