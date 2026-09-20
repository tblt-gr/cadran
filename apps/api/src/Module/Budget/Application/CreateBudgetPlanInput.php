<?php

declare(strict_types=1);

namespace App\Module\Budget\Application;

final readonly class CreateBudgetPlanInput
{
    public function __construct(
        public string $periodType,
        public string $period,
        public string $assetCode,
    ) {
    }
}
