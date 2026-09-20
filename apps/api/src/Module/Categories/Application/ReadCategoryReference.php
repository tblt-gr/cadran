<?php

declare(strict_types=1);

namespace App\Module\Categories\Application;

use App\Module\Categories\Domain\Category;
use App\Module\Categories\Domain\CategoryRepository;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * Publishes only the category fact another module's scope reference needs:
 * whether it exists in this workspace, its display label, whether it is
 * archived, and its ancestor chain — used by Budget to validate and present a
 * target's scope and to detect parent/child overlaps without reaching into the
 * category tree itself.
 */
final readonly class ReadCategoryReference
{
    public function __construct(private CategoryRepository $categories)
    {
    }

    public function __invoke(WorkspaceScope $workspace, string $categoryId): ?CategoryReferenceFact
    {
        $category = $this->categories->find($workspace, $categoryId);
        if (null === $category) {
            return null;
        }

        return new CategoryReferenceFact(
            $category->id,
            $category->label,
            null !== $category->archivedAt,
            $this->ancestorsOf($workspace, $category),
        );
    }

    /** @return list<string> */
    public function ancestorsOf(WorkspaceScope $workspace, Category $category): array
    {
        $ancestors = [];
        $cursor = $category->parentId;
        $guard = 0;
        while (null !== $cursor && $guard < Category::MAX_TREE_DEPTH) {
            $parent = $this->categories->find($workspace, $cursor);
            if (null === $parent) {
                break;
            }
            $ancestors[] = $parent->id;
            $cursor = $parent->parentId;
            ++$guard;
        }

        return $ancestors;
    }

    /** @return list<string> */
    public function ancestorIdsOf(WorkspaceScope $workspace, string $categoryId): array
    {
        $category = $this->categories->find($workspace, $categoryId);

        return null === $category ? [] : $this->ancestorsOf($workspace, $category);
    }
}
