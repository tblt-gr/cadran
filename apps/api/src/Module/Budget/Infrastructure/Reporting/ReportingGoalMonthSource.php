<?php

declare(strict_types=1);

namespace App\Module\Budget\Infrastructure\Reporting;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Budget\Application\Goals\GoalMonthSource;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reporting\Application\LoadAnnualMonths;
use App\Module\Reporting\Application\ReadFirstDataMonth;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(GoalMonthSource::class)]
final readonly class ReportingGoalMonthSource implements GoalMonthSource
{
    public function __construct(
        private LoadAnnualMonths $months,
        private ReadFirstDataMonth $firstData,
    ) {
    }

    public function between(WorkspaceScope $workspace, CalendarMonth $start, CalendarMonth $end, bool $includeUpcoming = false): array
    {
        $first = ($this->firstData)($workspace);

        return $this->months->between($workspace, $start, $end, null === $first ? null : CalendarMonth::fromString($first), $includeUpcoming);
    }
}
