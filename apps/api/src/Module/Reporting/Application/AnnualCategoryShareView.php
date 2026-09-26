<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

final readonly class AnnualCategoryShareView
{
    public function __construct(
        public ?string $categoryId,
        public string $label,
        public string $total,
        public string $share,
    ) {
    }
}
