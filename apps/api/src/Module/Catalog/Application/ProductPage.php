<?php

declare(strict_types=1);

namespace App\Module\Catalog\Application;

use App\Module\Catalog\Domain\EffectiveProduct;

final readonly class ProductPage
{
    /**
     * @param list<EffectiveProduct> $products
     */
    public function __construct(
        public array $products,
        public int $page,
        public int $perPage,
        public int $total,
    ) {
    }
}
