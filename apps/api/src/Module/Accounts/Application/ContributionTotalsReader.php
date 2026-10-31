<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * Everything paid into an account since it opened, withdrawals ignored. It is the measure a
 * contribution ceiling (a PEA) is compared to, whatever the account is worth.
 */
interface ContributionTotalsReader
{
    /** Null when the account's contributions are not tracked, never zero. */
    public function cumulative(WorkspaceScope $workspace, string $accountId, \DateTimeImmutable $asOf): ?AssetAmount;
}
