<?php

declare(strict_types=1);

namespace App\Module\Accounts\Infrastructure\Persistence;

use App\Module\Accounts\Application\AccountBalanceConflict;
use App\Module\Accounts\Domain\AccountBalanceSnapshot;
use App\Module\Accounts\Domain\AccountBalanceSnapshotRepository;
use App\Module\Accounts\Domain\AccountBalanceSnapshots;
use App\Module\Accounts\Domain\BalanceSnapshotSource;
use App\Module\Foundation\Domain\WorkspaceScope;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(AccountBalanceSnapshotRepository::class)]
final readonly class DbalAccountBalanceSnapshotRepository implements AccountBalanceSnapshotRepository
{
    private const string COLUMNS = 'id, workspace_id, account_id, as_of::text AS as_of, amount_value, amount_literal, amount_asset, source, reconciliation_status, comment, version, recorded_at::text AS recorded_at, recorded_by, superseded_at::text AS superseded_at';

    private const string JOINED_COLUMNS = 's.id, s.workspace_id, s.account_id, s.as_of::text AS as_of, s.amount_value, s.amount_literal, s.amount_asset, s.source, s.reconciliation_status, s.comment, s.version, s.recorded_at::text AS recorded_at, s.recorded_by, s.superseded_at::text AS superseded_at';

    public function __construct(private Connection $connection)
    {
    }

    public function findForAccount(WorkspaceScope $workspace, string $accountId): AccountBalanceSnapshots
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::COLUMNS.' FROM account_balance_snapshots WHERE workspace_id = :workspace_id AND account_id = :account_id'
            .' ORDER BY as_of, recorded_at, id',
            ['workspace_id' => $workspace->id, 'account_id' => $accountId],
        );

        return new AccountBalanceSnapshots(array_map(
            static fn (array $row): AccountBalanceSnapshot => AccountBalanceSnapshotRow::hydrate($row, $workspace),
            $rows,
        ));
    }

    public function findActive(
        WorkspaceScope $workspace,
        string $accountId,
        \DateTimeImmutable $asOf,
        BalanceSnapshotSource $source,
    ): ?AccountBalanceSnapshot {
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.' FROM account_balance_snapshots WHERE workspace_id = :workspace_id AND account_id = :account_id'
            .' AND as_of = :as_of AND source = :source AND superseded_at IS NULL',
            [
                'workspace_id' => $workspace->id,
                'account_id' => $accountId,
                'as_of' => $asOf->format('Y-m-d'),
                'source' => $source->value,
            ],
        );

        return false === $row ? null : AccountBalanceSnapshotRow::hydrate($row, $workspace);
    }

    public function findLatestForAccounts(
        WorkspaceScope $workspace,
        array $accountIds,
        \DateTimeImmutable $asOf,
    ): array {
        if ([] === $accountIds) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT DISTINCT ON (account_id) '.self::COLUMNS
            .' FROM account_balance_snapshots WHERE workspace_id = :workspace_id AND account_id IN (:account_ids)'
            .' AND as_of <= :as_of AND superseded_at IS NULL'
            .' ORDER BY account_id, as_of DESC, recorded_at DESC, id',
            [
                'workspace_id' => $workspace->id,
                'account_ids' => $accountIds,
                'as_of' => $asOf->format('Y-m-d'),
            ],
            ['account_ids' => ArrayParameterType::STRING],
        );

        $latest = [];
        foreach ($rows as $row) {
            $snapshot = AccountBalanceSnapshotRow::hydrate($row, $workspace);
            $latest[$snapshot->accountId] = $snapshot;
        }

        return $latest;
    }

    public function findLatestForAccountsOnDates(
        WorkspaceScope $workspace,
        array $accountIds,
        array $dates,
    ): array {
        if ([] === $accountIds || [] === $dates) {
            return [];
        }

        $placeholders = [];
        $parameters = ['workspace_id' => $workspace->id, 'account_ids' => $accountIds];
        foreach ($dates as $index => $date) {
            $placeholders[] = sprintf('(CAST(:date_%d AS date))', $index);
            $parameters['date_'.$index] = $date->format('Y-m-d');
        }

        // One DISTINCT ON per (day, account) lets PostgreSQL answer a whole
        // curve in a single pass instead of one round trip per point.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT DISTINCT ON (d.on_date, s.account_id) d.on_date::text AS on_date, '.self::JOINED_COLUMNS
            .' FROM (VALUES '.implode(', ', $placeholders).') AS d(on_date)'
            .' JOIN account_balance_snapshots s ON s.workspace_id = :workspace_id'
            .' AND s.account_id IN (:account_ids) AND s.superseded_at IS NULL AND s.as_of <= d.on_date'
            .' ORDER BY d.on_date, s.account_id, s.as_of DESC, s.recorded_at DESC, s.id',
            $parameters,
            ['account_ids' => ArrayParameterType::STRING],
        );

        $latest = [];
        foreach ($rows as $row) {
            $snapshot = AccountBalanceSnapshotRow::hydrate($row, $workspace);
            $latest[AccountRow::text($row['on_date'] ?? null)][$snapshot->accountId] = $snapshot;
        }

        return $latest;
    }

    public function pageForAccount(
        WorkspaceScope $workspace,
        string $accountId,
        int $limit,
        int $offset,
    ): array {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::COLUMNS.' FROM account_balance_snapshots WHERE workspace_id = :workspace_id AND account_id = :account_id'
            .' ORDER BY as_of DESC, recorded_at DESC, id DESC LIMIT :limit OFFSET :offset',
            [
                'workspace_id' => $workspace->id,
                'account_id' => $accountId,
                'limit' => $limit,
                'offset' => $offset,
            ],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return array_map(
            static fn (array $row): AccountBalanceSnapshot => AccountBalanceSnapshotRow::hydrate($row, $workspace),
            $rows,
        );
    }

    public function countForAccount(WorkspaceScope $workspace, string $accountId): int
    {
        return (int) AccountRow::text($this->connection->fetchOne(
            'SELECT count(*) FROM account_balance_snapshots WHERE workspace_id = :workspace_id AND account_id = :account_id',
            ['workspace_id' => $workspace->id, 'account_id' => $accountId],
        ));
    }

    public function add(AccountBalanceSnapshot $snapshot): void
    {
        try {
            $this->connection->insert('account_balance_snapshots', [
                'id' => $snapshot->id,
                'workspace_id' => $snapshot->workspace->id,
                ...AccountBalanceSnapshotRow::columns($snapshot),
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            throw new AccountBalanceConflict('An active snapshot already exists for this account, date and source.', previous: $exception);
        }
    }

    public function update(AccountBalanceSnapshot $snapshot, int $expectedVersion): bool
    {
        try {
            return 1 === (int) $this->connection->update(
                'account_balance_snapshots',
                AccountBalanceSnapshotRow::columns($snapshot),
                [
                    'workspace_id' => $snapshot->workspace->id,
                    'id' => $snapshot->id,
                    'version' => $expectedVersion,
                ],
            );
        } catch (UniqueConstraintViolationException $exception) {
            throw new AccountBalanceConflict('An active snapshot already exists for this account, date and source.', previous: $exception);
        }
    }
}
