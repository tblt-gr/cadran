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

    public function between(WorkspaceScope $workspace, CalendarMonth $start, CalendarMonth $end): array
    {
        $first = ($this->firstData)($workspace);
        $firstDataMonth = null === $first ? null : CalendarMonth::fromString($first);
        $result = [];
        for ($year = $start->year; $year <= $end->year; ++$year) {
            foreach (($this->months)($workspace, $year, $firstDataMonth) as $month) {
                if (self::ordinal($month->month) >= self::ordinal($start) && self::ordinal($month->month) <= self::ordinal($end)) {
                    $result[] = $month;
                }
            }
        }

        return $result;
    }

    private static function ordinal(CalendarMonth $month): int
    {
        return $month->year * 12 + $month->month;
    }
}
