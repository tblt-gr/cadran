<?php

declare(strict_types=1);

namespace App\Module\Categories\Application;

use App\Module\Categories\Domain\CategoryRepository;
use App\Module\Foundation\Domain\WorkspaceScope;

/** Publishes the bounded category facts needed to scope monthly budget actuals. */
final readonly class ReadBudgetCategoryFacts
{
    public function __construct(private CategoryRepository $categories)
    {
    }

    /** @return list<BudgetCategoryFact> */
    public function __invoke(WorkspaceScope $workspace): array
    {
        $categories = $this->categories->list($workspace, true, ReadBudgetCategoryFlags::MAX_CATEGORIES + 1, 0);
        if (count($categories) > ReadBudgetCategoryFlags::MAX_CATEGORIES) {
            throw new BudgetCategoryScopeTooLarge('A monthly budget comparison reads at most 500 categories.');
        }

        return array_map(
            static fn ($category): BudgetCategoryFact => new BudgetCategoryFact(
                $category->id,
                $category->parentId,
                $category->budgetIncluded,
                $category->type->value,
                $category->label,
                $category->icon,
                $category->color,
                $category->archivedAt?->format(DATE_ATOM),
            ),
            $categories,
        );
    }
}
