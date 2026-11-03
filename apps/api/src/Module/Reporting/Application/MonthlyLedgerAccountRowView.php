<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

final readonly class MonthlyLedgerAccountRowView
{
    public function __construct(
        public string $id,
        public string $label,
        public string $assetCode,
        public string $kind,
        public MonthlyMetricView $total,
        public int $movementCount,
        public bool $hasMovements,
        /** Scheduled movements, shown as if they had happened; never part of the realised total. */
        public ?string $pendingAmount = null,
        public ?string $pendingAssetCode = null,
        public int $pendingCount = 0,
        /** Effective color of the primary group, null without group: a border accent, never an amount input. */
        public ?string $primaryGroupColor = null,
    ) {
    }
}
