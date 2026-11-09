<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

final readonly class CreateGoalInput
{
    public function __construct(
        public string $label,
        public string $periodicity,
        public string $metric,
        public string $scopeType,
        public ?string $scopeRef,
        public string $startPeriod,
        public ?string $endPeriod,
        public string $valueType,
        public ?string $targetAmount,
        public ?string $targetAssetCode,
        public ?string $ratio,
        public ?GoalScheduleInput $schedule = null,
        public ?string $tolerance = null,
        public ?string $direction = null,
    ) {
    }
}
