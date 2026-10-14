<?php

namespace App\Integrations\Fiscal;

final class UnconfiguredFiscalProvider implements FiscalProvider
{
    public function name(): string
    {
        return 'unconfigured';
    }

    public function assertReady(): void
    {
        throw new FiscalIntegrationException('Fiscal issuance is pending a certified provider contract, authorization and tested integration.');
    }

    public function submit(FiscalRequest $request): FiscalResult
    {
        $this->assertReady();
        throw new FiscalIntegrationException('Fiscal provider is not configured.');
    }

    public function lookup(FiscalRequest $request): FiscalResult
    {
        $this->assertReady();
        throw new FiscalIntegrationException('Fiscal provider is not configured.');
    }
}
