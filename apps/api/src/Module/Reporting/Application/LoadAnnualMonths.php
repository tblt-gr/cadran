<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Accounts\Application\NetWorthScopeTooLarge;
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
 *
 * A closed month freezes the value of each account, not the group it belonged to: its group cells
 * are rebuilt at read time from the current tree, so every month answers with the same groups. A
 * snapshot written before account values were kept is replaced once by a fresh capture, which the
 * closure guarantees reads the same inputs.
 */
final readonly class LoadAnnualMonths
{
    public function __construct(
        private MonthlyProjector $projector,
        private PeriodClosureRepository $closures,
        private MonthSnapshotStore $snapshots,
        private WorkspaceCalendar $calendar,
        private RegroupMonthFigures $regroup,
    ) {
    }

    /** @return list<AnnualMonth> January to December */
    public function __invoke(WorkspaceScope $workspace, int $year, ?CalendarMonth $firstDataMonth): array
    {
        $today = $this->calendar->today();
        $states = ReportAggregator::monthStates(new CalendarMonth($year, 1), new CalendarMonth($year, 12), $firstDataMonth, $today);
        $months = [];
        $regroup = null;
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
            if (null !== $figures && null === $figures->accountWeights) {
                // A capture never overwrites a snapshot of the same closure, so the legacy one goes first.
                $this->snapshots->deleteForMonth($workspace, $month);
                $figures = null;
            }
            if (null === $figures) {
                $figures = MonthFigures::fromProjection(($this->projector)($workspace, $month, $today));
                $this->snapshots->capture($workspace, $month, $closure->id, $figures, $this->calendar->now());
            }
            try {
                $regroup ??= $this->regroup->forWorkspace($workspace);
            } catch (NetWorthScopeTooLarge $exception) {
                throw new MonthlyProjectionScopeTooLarge('The annual report scope exceeds its bounds.', previous: $exception);
            }
            $months[] = new AnnualMonth($month, $state, true, true, $regroup($figures));
        }

        return $months;
    }
}
