<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\WorkspaceScope;

/** Port to the movements held by the Transactions module. */
interface BalanceMovementReader
{
    /**
     * Booked and pending movements (never voided or rejected) dated after $afterExclusive,
     * summed by day.
     */
    public function dailyNet(
        WorkspaceScope $workspace,
        string $accountId,
        AssetCode $assetCode,
        \DateTimeImmutable $afterExclusive,
    ): AccountMovements;
}
