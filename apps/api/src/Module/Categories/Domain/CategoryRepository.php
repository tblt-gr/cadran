<?php

declare(strict_types=1);

namespace App\Module\Categories\Domain;

use App\Module\Foundation\Domain\WorkspaceScope;

interface CategoryRepository
{
    public function find(WorkspaceScope $workspace, string $id): ?Category;

    public function findForUpdate(WorkspaceScope $workspace, string $id): ?Category;

    /** @return list<Category> */
    public function list(
        WorkspaceScope $workspace,
        bool $includeArchived,
        int $limit,
        int $offset,
        ?CategoryType $type = null,
        ?string $search = null,
        bool $parentEligible = false,
    ): array;

    public function count(
        WorkspaceScope $workspace,
        bool $includeArchived,
        ?CategoryType $type = null,
        ?string $search = null,
        bool $parentEligible = false,
    ): int;

    /**
     * Whether an active sibling under $parentId already carries $label.
     *
     * $excludingIds names the categories that leave the active set in the same
     * transaction — the category being written, and, on a merge, the source being
     * archived. Without them a child folded into its own parent collides with the
     * parent the merge is about to archive.
     *
     * @param list<string> $excludingIds
     */
    public function hasActiveSiblingLabel(
        WorkspaceScope $workspace,
        CategoryType $type,
        ?string $parentId,
        string $label,
        array $excludingIds = [],
    ): bool;

    public function hasChildren(WorkspaceScope $workspace, string $id): bool;

    /** @return list<Category> every category below $ancestorId, archived ones included */
    public function descendantsForUpdate(WorkspaceScope $workspace, string $ancestorId): array;

    /** @return list<Category> every category below $ancestorId, read without locking */
    public function descendants(WorkspaceScope $workspace, string $ancestorId): array;

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
     * The display identity of each requested category: what a row needs to draw
     * a category, resolved in one bounded lookup rather than one per row.
     *
     * @param list<string> $ids
     *
     * @return array<string, array{label: string, icon: ?string, color: ?string}>
     */
    public function identitiesByIds(WorkspaceScope $workspace, array $ids): array;

    public function add(Category $category): void;

    /** Returns false when the expected version is stale. */
    public function update(Category $category, int $expectedVersion): bool;
}
