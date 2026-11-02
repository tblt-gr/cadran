<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

final readonly class MonthlyLedgerCategoryRowView
{
    public function __construct(
        public string $id,
        public string $label,
        public ?string $icon,
        public ?string $color,
        public bool $budgetIncluded,
        public bool $archived,
        public MonthlyMetricView $total,
        public int $movementCount,
        public bool $hasMovements,
        /** Scheduled movements, shown as if they had happened; never part of the realised total. */
        public ?string $pendingAmount = null,
        public ?string $pendingAssetCode = null,
        public int $pendingCount = 0,
    ) {
    }
}
