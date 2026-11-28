<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Budget\Domain\Goals\GoalEvaluationStatus;
use App\Module\Budget\Domain\Goals\GoalPace;
use App\Module\Budget\Domain\Goals\GoalReason;
use App\Module\Budget\Domain\Goals\GoalStatusQuality;

final readonly class GoalStatusView
{
    /** @param list<GoalStatusMonthView> $months */
    public function __construct(
        public string $goalId,
        public string $month,
        public string $periodicity,
        public string $direction,
        public ?string $valueType,
        public ?int $goalVersion,
        public ?string $scheduleMode,
        public bool $provisional,
        public GoalStatusMetricPolicyView $metricPolicy,
        public ?string $target,
        public ?string $achieved,
        public ?string $expected,
        public ?string $gap,
        public ?string $remaining,
        public ?string $excess,
        public GoalEvaluationStatus $status,
        public ?string $progressRatio,
        public ?string $progressRatioDisplay,
        public ?GoalReason $reason,
        public GoalStatusQuality $quality,
        public array $months = [],
        public ?string $ratio = null,
        public ?ReferenceIncomeView $referenceIncome = null,
        public ?string $actual = null,
        public ?GoalPace $pace = null,
        public ?string $paceGap = null,
    ) {
    }
}
