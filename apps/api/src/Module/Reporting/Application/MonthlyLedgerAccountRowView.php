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
    ) {
    }
}
