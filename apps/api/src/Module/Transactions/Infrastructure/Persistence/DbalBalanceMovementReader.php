<?php

declare(strict_types=1);

namespace App\Module\Transactions\Infrastructure\Persistence;

use App\Module\Accounts\Application\AccountMovements;
use App\Module\Accounts\Application\BalanceMovementReader;
use App\Module\Accounts\Domain\DayBalance;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use Doctrine\DBAL\Connection;

final readonly class DbalBalanceMovementReader implements BalanceMovementReader
{
    public function __construct(private Connection $connection)
    {
    }

    public function dailyNet(
        WorkspaceScope $workspace,
        string $accountId,
        AssetCode $assetCode,
        \DateTimeImmutable $afterExclusive,
    ): AccountMovements {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT booked_on,
                       SUM(amount_value)::text AS net,
                       bool_or(asset_code <> :asset_code) AS foreign_asset
                FROM transaction_transactions
                WHERE workspace_id = :workspace_id AND account_id = :account_id
                  AND state IN ('BOOKED', 'PENDING') AND voided_at IS NULL
                  AND booked_on > :after
                GROUP BY booked_on
                ORDER BY booked_on
                SQL,
            [
                'workspace_id' => $workspace->id,
                'account_id' => $accountId,
                'asset_code' => $assetCode->toString(),
                'after' => $afterExclusive->format('Y-m-d'),
            ],
        );

        $days = [];
        $mixed = false;
        foreach ($rows as $row) {
            $mixed = $mixed || in_array($row['foreign_asset'], [true, 't', '1', 1], true);
            $days[] = new DayBalance(
                new \DateTimeImmutable(TransactionRow::text($row['booked_on']), new \DateTimeZone('UTC')),
                DecimalValue::fromString(TransactionRow::canonicalNumeric(TransactionRow::text($row['net']))),
            );
        }

        return new AccountMovements($days, $mixed);
    }
}
