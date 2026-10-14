<?php

namespace App\Integrations\Fiscal;

use InvalidArgumentException;

final readonly class FiscalResult
{
    public function __construct(public string $status, public ?string $reference = null, public array $evidence = [])
    {
        if (! in_array($status, ['confirmed', 'rejected', 'outcome_unknown', 'pending'], true)
            || ($status === 'confirmed' && ($reference === null || trim($reference) === ''))) {
            throw new InvalidArgumentException('A fiscal confirmation requires a real external reference.');
        }
    }
}
