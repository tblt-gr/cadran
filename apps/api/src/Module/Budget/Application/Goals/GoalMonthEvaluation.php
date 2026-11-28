<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Budget\Domain\Goals\GoalReason;
use App\Module\Budget\Domain\Goals\GoalVersion;
use App\Module\Reporting\Domain\Aggregation\MonthState;

/** The target and the figure of one month of a monthly goal. */
final readonly class GoalMonthEvaluation
{
    /** @param list<int> $policyVersions */
    public function __construct(
        public ?GoalVersion $version,
        public MonthState $state,
        public GoalTarget $target,
        public ?string $actual,
        public ?GoalReason $reason,
        public ?int $policyVersion,
        public array $policyVersions,
    ) {
    }
}
