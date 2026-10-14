<?php

namespace App\Integrations\Fiscal;

final readonly class FiscalRequest
{
    public function __construct(public int $documentId, public string $operationKey, public array $payload) {}
}
