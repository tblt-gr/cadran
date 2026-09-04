<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

final readonly class ProductModelPage
{
    /**
     * @param list<ProductModelView> $items
     */
    public function __construct(
        public array $items,
        public int $page,
        public int $perPage,
        public int $total,
    ) {
    }
}
