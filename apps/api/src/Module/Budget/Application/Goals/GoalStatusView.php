<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

final readonly class GoalStatusView
{
    /** @param list<GoalStatusMonthView> $months */
    public function __construct(
        public string $goalId,
        public string $month,
        public string $kind,
        public string $direction,
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
        public string $status,
        public ?string $progressRatio,
        public ?string $progressRatioDisplay,
        public ?string $reason,
        public string $quality,
        public array $months = [],
        public ?string $ratio = null,
        public ?ReferenceIncomeView $referenceIncome = null,
        public ?string $actual = null,
    ) {
    }
}
