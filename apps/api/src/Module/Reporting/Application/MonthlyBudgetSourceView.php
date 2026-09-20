<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

final readonly class MonthlyBudgetSourceView
{
    public function __construct(
        public string $id,
        public string $bookedOn,
        public string $rawLabel,
        public string $amount,
        public string $assetCode,
    ) {
    }
}
