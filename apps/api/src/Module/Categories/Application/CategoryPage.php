<?php

declare(strict_types=1);

namespace App\Module\Categories\Application;

final readonly class CategoryPage
{
    /** @param list<CategoryView> $items */
    public function __construct(
        public array $items,
        public int $page,
        public int $perPage,
        public int $total,
    ) {
    }
}
