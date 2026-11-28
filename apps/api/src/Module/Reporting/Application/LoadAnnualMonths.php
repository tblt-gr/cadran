<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Accounts\Application\NetWorthScopeTooLarge;
use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Accounts\Domain\PeriodClosure;
use App\Module\Accounts\Domain\PeriodClosureRepository;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reporting\Domain\Aggregation\MonthState;
use App\Module\Reporting\Domain\Aggregation\ReportAggregator;

/**
 * The months of a year or of any inclusive range, with their closures, snapshots and policies read in one
 * batch. A closed month is read from the snapshot of its active closure; an open month is computed live. A
 * month before the first data or after today needs no read at all.
 *
 * It never writes: a closed month without a readable snapshot, or with one written before account values were
 * kept, is computed live until {@see CaptureMissingSnapshots} captures it under the closure lock.
 *
 * A closed month freezes the value of each account, not the group it belonged to: its group cells are rebuilt
 * at read time from the current tree, so every month answers with the same groups.
 */
final readonly class LoadAnnualMonths
{
    public function __construct(
        private MonthlyProjector $projector,
        private PeriodClosureRepository $closures,
        private MonthSnapshotStore $snapshots,
        private WorkspaceCalendar $calendar,
        private RegroupMonthFigures $regroup,
        private ResolveMetricPolicy $policies,
    ) {
    }

    /** @return list<AnnualMonth> January to December */
    public function __invoke(WorkspaceScope $workspace, int $year, ?CalendarMonth $firstDataMonth, bool $includeUpcoming = false): array
    {
        return $this->between($workspace, new CalendarMonth($year, 1), new CalendarMonth($year, 12), $firstDataMonth, $includeUpcoming);
    }

    /** @return list<AnnualMonth> one per month of the inclusive range, oldest first */
    public function between(WorkspaceScope $workspace, CalendarMonth $first, CalendarMonth $last, ?CalendarMonth $firstDataMonth, bool $includeUpcoming = false): array
    {
        $today = $this->calendar->today();
        $states = [];
        // Year by year: a range of decades stays within the population bound of one aggregation.
        for ($year = $first->year; $year <= $last->year; ++$year) {
            $states += ReportAggregator::monthStates(
                $year === $first->year ? $first : new CalendarMonth($year, 1),
                $year === $last->year ? $last : new CalendarMonth($year, 12),
                $firstDataMonth,
                $today,
            );
        }
        $readable = [];
        foreach ($states as $key => $state) {
            if (MonthState::FUTURE !== $state && MonthState::NO_DATA !== $state) {
                $readable[$key] = CalendarMonth::fromString($key);
            }
        }
        $closures = $this->closuresOf($workspace, array_values($readable));
        $stored = array_filter(
            $this->snapshots->findMany($workspace, array_map(static fn (PeriodClosure $closure): string => $closure->id, array_intersect_key($closures, $readable))),
            static fn (MonthFigures $figures): bool => null !== $figures->accountWeights,
        );
        $live = array_values(array_diff_key($readable, $stored));
        $policies = [];
        foreach ([] === $live ? [] : $this->policies->forMonths($workspace, $live, $closures) as $index => $policy) {
            $policies[$live[$index]->key()] = $policy;
        }

        $months = [];
        $regroup = null;
        foreach ($states as $key => $state) {
            $month = CalendarMonth::fromString($key);
            if (!isset($readable[$key])) {
                $months[] = new AnnualMonth($month, $state, false, false, null);
                continue;
            }
            $closed = isset($closures[$key]);
            $figures = $stored[$key] ?? MonthFigures::fromProjection(
                // A closed month holds no planned movement, whatever the caller asks for the open ones.
                ($this->projector)($workspace, $month, $today, !$closed && $includeUpcoming, $policies[$key]),
            );
            if (!$closed) {
                $months[] = new AnnualMonth($month, $state, false, false, $figures);
                continue;
            }
            try {
                $regroup ??= $this->regroup->forWorkspace($workspace);
            } catch (NetWorthScopeTooLarge $exception) {
                throw new MonthlyProjectionScopeTooLarge('The annual report scope exceeds its bounds.', previous: $exception);
            }
            $months[] = new AnnualMonth($month, $state, true, isset($stored[$key]), $regroup($figures));
        }

        return $months;
    }

    /**
     * @param list<CalendarMonth> $months oldest first
     *
     * @return array<string, PeriodClosure> the active closures, keyed by `YYYY-MM`
     */
    private function closuresOf(WorkspaceScope $workspace, array $months): array
    {
        if ([] === $months) {
            return [];
        }
        $closures = [];
        foreach ($this->closures->activeClosuresBetween($workspace, $months[0], $months[array_key_last($months)]) as $closure) {
            $closures[$closure->month->key()] = $closure;
        }

        return $closures;
    }
}
