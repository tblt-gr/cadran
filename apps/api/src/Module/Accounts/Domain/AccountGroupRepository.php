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

    /**
     * Color slots held by the siblings under `$parentId` (archived included).
     *
     * @return list<int>
     */
    public function usedColorSlots(WorkspaceScope $workspace, ?string $parentId): array;

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

    /**
     * Locks every sibling row under `$parentId` (archived included, id order, so
     * two moves never deadlock) and returns at most `$limit` of them in saved
     * order: sort order, then label, then id.
     *
     * @return list<AccountGroup>
     */
    public function lockSiblings(WorkspaceScope $workspace, ?string $parentId, int $limit): array;

    /**
     * Rewrites `sort_order` as `0..n-1` for the listed siblings without bumping
     * their version: ordering is not an edit of the group.
     *
     * @param list<string> $orderedIds every sibling under `$parentId`, archived included
     */
    public function saveSiblingOrder(WorkspaceScope $workspace, ?string $parentId, array $orderedIds): void;

    /**
     * The order a group appended after the last sibling of `$parentId` takes.
     * When that would exceed the cap, siblings are renumbered to `0..n-1` first.
     */
    public function appendPosition(WorkspaceScope $workspace, ?string $parentId): int;

    public function add(AccountGroup $group): void;

    /** Returns false when the expected version is stale. */
    public function update(AccountGroup $group, int $expectedVersion): bool;
}
