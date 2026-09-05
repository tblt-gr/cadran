<?php

declare(strict_types=1);

namespace App\Module\Categories\Application;

use App\Module\Categories\Domain\Category;
use App\Module\Categories\Domain\CategoryReplacementRepository;
use App\Module\Categories\Domain\CategoryRepository;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * Builds the single-category answer every write endpoint returns.
 *
 * Each use case resolving the parent label, the children flag and the
 * redirection on its own is how a client ends up caching a category whose
 * redirection disappears after an unrelated rename. {@see ListCategories}
 * keeps its own batched resolution: a page must not query per row.
 */
final readonly class PresentCategory
{
    public function __construct(
        private CategoryRepository $categories,
        private CategoryReplacementRepository $replacements,
    ) {
    }

    public function __invoke(WorkspaceScope $workspace, Category $category): CategoryView
    {
        $replacement = $this->replacements->findBySource($workspace, $category->id);
        $labelled = array_values(array_filter([$category->parentId, $replacement?->targetCategoryId]));
        $labels = $this->categories->labelsByIds($workspace, $labelled);

        return CategoryView::fromCategory(
            $category,
            $this->categories->hasChildren($workspace, $category->id),
            null === $category->parentId ? null : ($labels[$category->parentId] ?? null),
            null === $replacement ? null : CategoryReplacementView::of(
                $replacement,
                $labels[$replacement->targetCategoryId] ?? null,
            ),
        );
    }
}
