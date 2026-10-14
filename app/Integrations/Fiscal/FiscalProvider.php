<?php

namespace App\Integrations\Fiscal;

interface FiscalProvider
{
    public function name(): string;

    /** Must refuse before a network operation when credentials/authorization are unavailable. */
    public function assertReady(): void;

    public function submit(FiscalRequest $request): FiscalResult;

    /** Status reconciliation must not issue a new fiscal document. */
    public function lookup(FiscalRequest $request): FiscalResult;
}
