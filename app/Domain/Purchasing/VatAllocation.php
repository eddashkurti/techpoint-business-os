<?php

declare(strict_types=1);

namespace App\Domain\Purchasing;

use App\Domain\Finance\Money;

final readonly class VatAllocation
{
    public function __construct(public Money $recoverable, public Money $nonrecoverable) {}
}
