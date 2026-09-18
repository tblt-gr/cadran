<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Foundation\Domain\WorkspaceScope;

interface AccountBalanceSnapshotRepository
{
    public function findForAccount(WorkspaceScope $workspace, string $accountId): AccountBalanceSnapshots;

    /** One snapshot of this account by identifier, active or superseded. */
    public function find(WorkspaceScope $workspace, string $accountId, string $id): ?AccountBalanceSnapshot;

    public function findActive(
        WorkspaceScope $workspace,
        string $accountId,
        \DateTimeImmutable $asOf,
        BalanceSnapshotSource $source,
    ): ?AccountBalanceSnapshot;

    /**
     * The latest active snapshot on or before $asOf for each requested account.
     *
     * @param list<string> $accountIds
     *
     * @return array<string, AccountBalanceSnapshot>
     */
    public function findLatestForAccounts(
        WorkspaceScope $workspace,
        array $accountIds,
        \DateTimeImmutable $asOf,
    ): array;

    /**
     * The latest active snapshot on or before each requested day, for each
     * requested account. One round trip answers a whole history curve.
     *
     * @param list<string>             $accountIds
     * @param list<\DateTimeImmutable> $dates
     *
     * @return array<string, array<string, AccountBalanceSnapshot>> keyed by
     *                                                              `Y-m-d` then by account
     */
    public function findLatestForAccountsOnDates(
        WorkspaceScope $workspace,
        array $accountIds,
        array $dates,
    ): array;

    /**
     * Snapshot history newest day first, superseded rows included.
     *
     * @return list<AccountBalanceSnapshot>
     */
    public function pageForAccount(
        WorkspaceScope $workspace,
        string $accountId,
        int $limit,
        int $offset,
    ): array;

    public function countForAccount(WorkspaceScope $workspace, string $accountId): int;

    public function add(AccountBalanceSnapshot $snapshot): void;

    /** Returns false when the expected version is stale. */
    public function update(AccountBalanceSnapshot $snapshot, int $expectedVersion): bool;
}
