<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application\Double;

use App\Module\Accounts\Application\AccountMovements;
use App\Module\Accounts\Application\BalanceMovementReader;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\WorkspaceScope;

/** An account without any live movement, for the use cases that do not exercise the balance floor. */
final class NoMovementsReader implements BalanceMovementReader
{
    public function dailyNet(WorkspaceScope $workspace, string $accountId, AssetCode $assetCode, \DateTimeImmutable $afterExclusive): AccountMovements
    {
        return new AccountMovements([], false);
    }
}
