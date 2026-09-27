<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Accounts\Domain\PeriodClosureRepository;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reporting\Domain\Aggregation\MonthState;
use App\Module\Reporting\Domain\Aggregation\ReportAggregator;

/**
 * The twelve months of a year. A closed month is read from the snapshot of its active closure,
 * captured on the spot when none exists yet; an open month is computed live. A month before the
 * first data or after today needs no read at all.
 */
final readonly class LoadAnnualMonths
{
    public function __construct(
        private MonthlyProjector $projector,
        private PeriodClosureRepository $closures,
        private MonthSnapshotStore $snapshots,
        private WorkspaceCalendar $calendar,
    ) {
    }

    /** @return list<AnnualMonth> January to December */
    public function __invoke(WorkspaceScope $workspace, int $year, ?CalendarMonth $firstDataMonth): array
    {
        $today = $this->calendar->today();
        $states = ReportAggregator::monthStates(new CalendarMonth($year, 1), new CalendarMonth($year, 12), $firstDataMonth, $today);
        $months = [];
        foreach ($states as $key => $state) {
            $month = CalendarMonth::fromString($key);
            if (MonthState::FUTURE === $state || MonthState::NO_DATA === $state) {
                $months[] = new AnnualMonth($month, $state, false, false, null);
                continue;
            }
            $closure = $this->closures->findActive($workspace, $month);
            if (null === $closure) {
                $months[] = new AnnualMonth($month, $state, false, false, MonthFigures::fromProjection(($this->projector)($workspace, $month, $today)));
                continue;
            }
            $figures = $this->snapshots->find($workspace, $month, $closure->id);
            if (null === $figures) {
                $figures = MonthFigures::fromProjection(($this->projector)($workspace, $month, $today));
                $this->snapshots->capture($workspace, $month, $closure->id, $figures, $this->calendar->now());
            }
            $months[] = new AnnualMonth($month, $state, true, true, $figures);
        }

        return $months;
    }
}
