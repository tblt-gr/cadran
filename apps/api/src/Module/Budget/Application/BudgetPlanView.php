<?php

declare(strict_types=1);

namespace App\Module\Budget\Application;

final readonly class BudgetPlanView
{
    public function __construct(
        public string $id,
        public string $periodType,
        public string $period,
        public string $assetCode,
        public string $state,
        public int $version,
    ) {
    }
}
