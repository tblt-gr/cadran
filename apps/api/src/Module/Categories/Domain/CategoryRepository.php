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

    public function hasActiveSiblingLabel(
        WorkspaceScope $workspace,
        CategoryType $type,
        ?string $parentId,
        string $label,
        ?string $excludingId = null,
    ): bool;

    public function hasChildren(WorkspaceScope $workspace, string $id): bool;

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

    public function add(Category $category): void;

    /** Returns false when the expected version is stale. */
    public function update(Category $category, int $expectedVersion): bool;
}
