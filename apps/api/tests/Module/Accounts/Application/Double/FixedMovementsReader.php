<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application\Double;

use App\Module\Accounts\Application\AccountMovements;
use App\Module\Accounts\Application\BalanceMovementReader;
use App\Module\Accounts\Domain\DayBalance;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;

/** Day-end net movements fixed by the test, read like the real port: strictly after the given day. */
final readonly class FixedMovementsReader implements BalanceMovementReader
{
    /** @param array<string, string> $net signed net movement keyed by Y-m-d */
    public function __construct(private array $net, private bool $mixedAssets = false)
    {
    }

    public function dailyNet(WorkspaceScope $workspace, string $accountId, AssetCode $assetCode, \DateTimeImmutable $afterExclusive): AccountMovements
    {
        $days = [];
        foreach ($this->net as $day => $amount) {
            $date = new \DateTimeImmutable($day, new \DateTimeZone('UTC'));
            if ($date > $afterExclusive) {
                $days[] = new DayBalance($date, DecimalValue::fromString($amount));
            }
        }

        return new AccountMovements($days, $this->mixedAssets);
    }
}
