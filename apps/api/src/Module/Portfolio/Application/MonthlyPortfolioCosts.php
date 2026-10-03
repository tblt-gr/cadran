<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Domain\DecimalValue;

final readonly class MonthlyPortfolioCosts
{
    /**
     * @param list<MonthlyPortfolioCostItem> $items ordered by account label
     *
     * The totals and the asset are null, never zero, when the items mix assets: adding them up would be meaningless
     */
    public function __construct(
        public array $items,
        public ?DecimalValue $totalFees,
        public ?DecimalValue $totalTaxes,
        public ?string $assetCode,
    ) {
    }
}
