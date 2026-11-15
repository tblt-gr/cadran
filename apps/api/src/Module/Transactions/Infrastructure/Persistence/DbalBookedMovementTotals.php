<?php

declare(strict_types=1);

namespace App\Module\Transactions\Infrastructure\Persistence;

use App\Module\Accounts\Application\BookedMovementTotals;
use App\Module\Accounts\Application\NetWorthScopeTooLarge;
use App\Module\Accounts\Domain\MovementTotal;
use App\Module\Accounts\Domain\MovementWindow;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use Doctrine\DBAL\Connection;

final readonly class DbalBookedMovementTotals implements BookedMovementTotals
{
    public function __construct(private Connection $connection)
    {
    }

    public function forWindows(WorkspaceScope $workspace, array $windows): array
    {
        if (count($windows) > BookedMovementTotals::MAX_WINDOWS) {
            throw new NetWorthScopeTooLarge('A movement batch accepts at most 30000 windows.');
        }
        if ([] === $windows) {
            return [];
        }
        $result = [];
        $unique = [];
        foreach ($windows as $window) {
            $result[$window->key()] = new MovementTotal(null, 0, 0, false);
            $unique[$window->key()] = $window;
        }
        $windows = array_values($unique);
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT w.account_id, w.after_exclusive, w.up_to_inclusive,
                       SUM(t.amount_value) FILTER (WHERE t.state = 'BOOKED' AND t.voided_at IS NULL AND t.asset_code = w.asset_code)::text AS total,
                       COUNT(*) FILTER (WHERE t.state = 'BOOKED' AND t.voided_at IS NULL AND t.asset_code = w.asset_code) AS booked_count,
                       COUNT(*) FILTER (WHERE t.state = 'BOOKED' AND t.voided_at IS NULL AND t.asset_code <> w.asset_code) AS foreign_count,
                       COUNT(*) FILTER (WHERE t.state = 'PENDING') AS pending_count
                FROM unnest(:account_ids::uuid[], :afters::date[], :ups::date[], :assets::varchar[])
                    AS w(account_id, after_exclusive, up_to_inclusive, asset_code)
                JOIN transaction_transactions t ON t.workspace_id = :workspace_id AND t.account_id = w.account_id
                    AND t.booked_on > w.after_exclusive AND t.booked_on <= w.up_to_inclusive
                GROUP BY w.account_id, w.after_exclusive, w.up_to_inclusive
                SQL,
            [
                'workspace_id' => $workspace->id,
                'account_ids' => TransactionRow::postgresArray(array_map(static fn (MovementWindow $w): string => $w->accountId, $windows)),
                'afters' => TransactionRow::postgresArray(array_map(static fn (MovementWindow $w): string => $w->afterExclusive->format('Y-m-d'), $windows)),
                'ups' => TransactionRow::postgresArray(array_map(static fn (MovementWindow $w): string => $w->upToInclusive->format('Y-m-d'), $windows)),
                'assets' => TransactionRow::postgresArray(array_map(static fn (MovementWindow $w): string => $w->assetCode->toString(), $windows)),
            ],
        );
        foreach ($rows as $row) {
            $key = TransactionRow::text($row['account_id']).'|'.TransactionRow::text($row['after_exclusive']).'|'.TransactionRow::text($row['up_to_inclusive']);
            $result[$key] = new MovementTotal(
                null === $row['total'] ? null : DecimalValue::fromString(TransactionRow::canonicalNumeric(TransactionRow::text($row['total']))),
                (int) TransactionRow::text($row['booked_count']),
                (int) TransactionRow::text($row['pending_count']),
                0 < (int) TransactionRow::text($row['foreign_count']),
            );
        }

        return $result;
    }
}
