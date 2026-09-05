<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Foundation\Domain\WorkspaceScope;

interface AccountGroupRepository
{
    public function find(WorkspaceScope $workspace, string $id): ?AccountGroup;

    public function findForUpdate(WorkspaceScope $workspace, string $id): ?AccountGroup;

    /** @return list<AccountGroup> */
    public function list(WorkspaceScope $workspace, bool $includeArchived, int $limit, int $offset): array;

    public function count(WorkspaceScope $workspace, bool $includeArchived): int;

    public function hasActiveSiblingLabel(
        WorkspaceScope $workspace,
        ?string $parentId,
        string $label,
        ?string $excludingId = null,
    ): bool;

    public function hasChildren(WorkspaceScope $workspace, string $id): bool;

    /**
     * Locked descendants of `$ancestorId`, closest child first.
     *
     * @return list<AccountGroup>
     */
    public function descendantsForUpdate(WorkspaceScope $workspace, string $ancestorId): array;

    public function isReferencedByAccount(WorkspaceScope $workspace, string $id): bool;

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    public function parentIdsWithChildren(WorkspaceScope $workspace, array $ids): array;

    /**
     * @param list<string> $ids
     *
     * @return array<string, string>
     */
    public function labelsByIds(WorkspaceScope $workspace, array $ids): array;

    public function add(AccountGroup $group): void;

    /** Returns false when the expected version is stale. */
    public function update(AccountGroup $group, int $expectedVersion): bool;
}
