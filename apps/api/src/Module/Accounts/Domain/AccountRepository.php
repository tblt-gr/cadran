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

    public function count(
        WorkspaceScope $workspace,
        bool $includeArchived,
        bool $includeClosed,
        ?AccountKind $kind = null,
    ): int;

    /**
     * Active accounts included in net worth, capped at $limit.
     *
     * Closure is deliberately not filtered here: whether a closed account
     * still counts depends on the business day being aggregated, so the
     * decision belongs to the calculation rather than to the query.
     *
     * @return list<Account>
     */
    public function listForNetWorth(WorkspaceScope $workspace, int $limit): array;

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

    public function hasActiveLabel(
        WorkspaceScope $workspace,
        string $label,
        ?string $excludingId = null,
    ): bool;

    public function add(Account $account): void;

    /** Returns false when the expected version is stale. */
    public function update(Account $account, int $expectedVersion): bool;
}
