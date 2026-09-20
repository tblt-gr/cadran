<?php

declare(strict_types=1);

namespace App\Module\Categories\Application;

final readonly class BudgetCategoryFact
{
    public function __construct(
        public string $id,
        public ?string $parentId,
        public bool $budgetIncluded,
    ) {
    }
}
