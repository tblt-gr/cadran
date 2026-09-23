<?php

declare(strict_types=1);

namespace App\Module\Categories\Application;

final readonly class BudgetCategoryFact
{
    public function __construct(
        public string $id,
        public ?string $parentId,
        public bool $budgetIncluded,
        public string $type,
        public string $label,
        public ?string $icon,
        public ?string $color,
        public ?string $archivedAt,
    ) {
    }
}
