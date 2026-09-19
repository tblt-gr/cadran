<?php

declare(strict_types=1);

namespace App\Module\Categories\Application;

use App\Module\Categories\Domain\CategoryRepository;
use App\Module\Foundation\Domain\WorkspaceScope;

/** Publishes only the category fact reporting owns the right to consume. */
final readonly class ReadBudgetCategoryFlags
{
    public const int MAX_CATEGORIES = 500;

    public function __construct(private CategoryRepository $categories)
    {
    }

    /** @return array<string, bool> */
    public function __invoke(WorkspaceScope $workspace): array
    {
        $categories = $this->categories->list($workspace, true, self::MAX_CATEGORIES + 1, 0);
        if (count($categories) > self::MAX_CATEGORIES) {
            throw new BudgetCategoryScopeTooLarge('A monthly projection reads at most 500 categories.');
        }

        $flags = [];
        foreach ($categories as $category) {
            $flags[$category->id] = $category->budgetIncluded;
        }

        return $flags;
    }
}
