<?php

declare(strict_types=1);

namespace App\Module\Categories\Application;

final readonly class CreateCategoryInput
{
    /** @param list<string> $defaultAnalyticAxes */
    public function __construct(
        public string $type,
        public string $label,
        public ?string $parentId,
        public ?string $icon,
        public ?string $color,
        public array $defaultAnalyticAxes,
        public bool $budgetIncluded,
        public int $sortOrder,
    ) {
    }
}
