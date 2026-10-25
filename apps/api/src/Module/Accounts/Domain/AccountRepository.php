<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Catalog\Domain\AccountKind;
use App\Module\Foundation\Domain\WorkspaceScope;

interface AccountRepository
{
    /** Reads one account without locking it, for a query that will not write. */
    public function find(WorkspaceScope $workspace, string $id): ?Account;

    public function findForUpdate(WorkspaceScope $workspace, string $id): ?Account;

    /** @return list<Account> */
    public function list(
        WorkspaceScope $workspace,
        bool $includeArchived,
        bool $includeClosed,
        int $limit,
        int $offset,
        ?AccountKind $kind = null,
    ): array;

    /**
     * Non-archived portfolio-mode accounts ordered by label, including closed history.
     *
     * @return list<Account>
     */
    public function listPortfolios(WorkspaceScope $workspace, int $limit): array;

    public function count(
        WorkspaceScope $workspace,
        bool $includeArchived,
        bool $includeClosed,
        ?AccountKind $kind = null,
    ): int;

    /**
     * Accounts included in net worth and active on at least one day of the
     * requested historical interval, capped at $limit.
     *
     * @return list<Account>
     */
    public function listForNetWorthDuring(
        WorkspaceScope $workspace,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeZone $workspaceTimezone,
        int $limit,
    ): array;

    /**
     * Accounts active at some point of $from..$to, capped at $limit. Opening
     * and closing days are inclusive; the workspace-local day of archival is
     * excluded.
     *
     * @return list<Account>
     */
    public function listOpenDuring(
        WorkspaceScope $workspace,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeZone $workspaceTimezone,
        int $limit,
    ): array;

    /**
     * Members of one primary group (null for none) in saved order, after the
     * given rank, with lifecycle filters applied after ranking so a rank always
     * counts hidden members too.
     *
     * @return list<RankedAccount>
     */
    public function listInGroupOrder(
        WorkspaceScope $workspace,
        ?string $groupId,
        bool $includeArchived,
        bool $includeClosed,
        int $afterRank,
        int $limit,
    ): array;

    /**
     * Every member of the group, whatever its lifecycle, in saved order,
     * capped at $limit.
     *
     * @return list<string>
     */
    public function memberIdsInOrder(WorkspaceScope $workspace, ?string $groupId, int $limit): array;

    /** @return array<string, int> visible members keyed by {@see AccountOrdering::key()} */
    public function countByPrimaryGroup(WorkspaceScope $workspace, bool $includeArchived, bool $includeClosed): array;

    public function hasActiveLabel(
        WorkspaceScope $workspace,
        string $label,
        ?string $excludingId = null,
    ): bool;

    public function add(Account $account): void;

    /**
     * Returns false when the expected version is stale, without changing any account.
     * Selecting a primary atomically clears the previous primary in this workspace
     * and advances its version, so an old editor cannot restore an implicit default.
     */
    public function update(Account $account, int $expectedVersion): bool;
}
