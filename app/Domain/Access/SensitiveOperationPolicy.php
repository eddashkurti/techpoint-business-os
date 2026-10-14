<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\Shared\DomainViolation;

final class SensitiveOperationPolicy
{
    /** Roles do not imply grants. Scope validation is additionally required in repository/API policies. @param list<string> $explicitGrants */
    public function authorize(string $requiredPermission, array $explicitGrants): void
    {
        if ($requiredPermission === '' || ! in_array($requiredPermission, $explicitGrants, true)) {
            throw new DomainViolation('This operation requires an explicit permission grant.');
        }
    }

    public function assertOpenPeriod(bool $periodClosed): void
    {
        if ($periodClosed) {
            throw new DomainViolation('Posted corrections require an open accounting period or an authorized reopening.');
        }
    }

    public function assertCorrectionReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw new DomainViolation('A correction or adjustment requires a reason.');
        }
    }
}
