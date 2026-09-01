<?php

declare(strict_types=1);

namespace App\Module\Categories\Application;

final readonly class UpdateCategoryInput
{
    /** @param list<string> $defaultAnalyticAxes */
    public function __construct(
        public string $type,
        public string $label,
        public ?string $icon,
        public ?string $color,
        public array $defaultAnalyticAxes,
        public bool $budgetIncluded,
        public int $sortOrder,
        public int $version,
    ) {
    }
}
