<?php

declare(strict_types=1);

namespace App\Module\Budget\Application;

final readonly class UpdateBudgetPlanInput
{
    public function __construct(
        public string $planId,
        public int $expectedVersion,
        public string $periodType,
        public string $period,
        public string $assetCode,
    ) {
    }
}
