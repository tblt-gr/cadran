<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

final readonly class AnnualTopCategoriesView
{
    /** @param list<AnnualCategoryShareView> $items */
    public function __construct(
        public array $items,
        public ?AnnualCategoryShareView $other,
        public ?string $reason,
        public ?string $assetCode = null,
    ) {
    }
}
