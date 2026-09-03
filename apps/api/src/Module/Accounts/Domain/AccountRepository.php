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

    public function hasActiveLabel(
        WorkspaceScope $workspace,
        string $label,
        ?string $excludingId = null,
    ): bool;

    public function add(Account $account): void;

    /** Returns false when the expected version is stale. */
    public function update(Account $account, int $expectedVersion): bool;
}
