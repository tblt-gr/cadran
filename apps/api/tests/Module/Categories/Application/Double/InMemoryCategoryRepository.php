<?php

declare(strict_types=1);

namespace App\Tests\Module\Categories\Application\Double;

use App\Module\Categories\Domain\Category;
use App\Module\Categories\Domain\CategoryRepository;
use App\Module\Categories\Domain\CategoryType;
use App\Module\Foundation\Domain\WorkspaceScope;

final class InMemoryCategoryRepository implements CategoryRepository
{
    /** @var array<string, Category> */
    private array $categories = [];

    public function find(WorkspaceScope $workspace, string $id): ?Category
    {
        $category = $this->categories[$id] ?? null;

        return null !== $category && $category->workspace->equals($workspace) ? $category : null;
    }

    public function findForUpdate(WorkspaceScope $workspace, string $id): ?Category
    {
        return $this->find($workspace, $id);
    }

    public function list(WorkspaceScope $workspace, bool $includeArchived, int $limit, int $offset, ?CategoryType $type = null, ?string $search = null, bool $parentEligible = false): array
    {
        $categories = array_values(array_filter(
            $this->categories,
            static fn (Category $category): bool => $category->workspace->equals($workspace) && ($includeArchived || null === $category->archivedAt),
        ));

        return array_slice($categories, $offset, $limit);
    }

    public function count(WorkspaceScope $workspace, bool $includeArchived, ?CategoryType $type = null, ?string $search = null, bool $parentEligible = false): int
    {
        return count($this->list($workspace, $includeArchived, PHP_INT_MAX, 0, $type, $search, $parentEligible));
    }

    public function hasActiveSiblingLabel(WorkspaceScope $workspace, CategoryType $type, ?string $parentId, string $label, array $excludingIds = []): bool
    {
        return false;
    }

    public function hasChildren(WorkspaceScope $workspace, string $id): bool
    {
        foreach ($this->categories as $category) {
            if ($category->workspace->equals($workspace) && $category->parentId === $id) {
                return true;
            }
        }

        return false;
    }

    public function descendantsForUpdate(WorkspaceScope $workspace, string $ancestorId): array
    {
        return [];
    }

    public function descendants(WorkspaceScope $workspace, string $ancestorId): array
    {
        return [];
    }

    public function parentIdsWithChildren(WorkspaceScope $workspace, array $ids): array
    {
        return [];
    }

    public function labelsByIds(WorkspaceScope $workspace, array $ids): array
    {
        $labels = [];
        foreach ($ids as $id) {
            if (isset($this->categories[$id])) {
                $labels[$id] = $this->categories[$id]->label;
            }
        }

        return $labels;
    }

    public function identitiesByIds(WorkspaceScope $workspace, array $ids): array
    {
        return [];
    }

    public function add(Category $category): void
    {
        $this->categories[$category->id] = $category;
    }

    public function update(Category $category, int $expectedVersion): bool
    {
        $current = $this->categories[$category->id] ?? null;
        if (null === $current || $current->version !== $expectedVersion) {
            return false;
        }

        $this->categories[$category->id] = $category;

        return true;
    }
}
