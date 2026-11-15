<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Reporting\Domain\Aggregation\AggregateQuality;
use App\Module\Reporting\Domain\Aggregation\IncompleteMonths;
use App\Module\Reporting\Domain\Aggregation\MonthState;

/**
 * The all-years comparison report: one row per year of the range, derived from the same per-year
 * computation as the annual report ({@see ComputeAnnualReport}), plus the year-over-year change and
 * a summary row over complete years. Figures are nominal: nothing here adjusts for inflation.
 */
final readonly class ReadMultiYearReport
{
    public const int FIRST_YEAR = ComputeAnnualReport::FIRST_YEAR;
    /** `to - from` at most this many years, i.e. at most 15 rows. */
    public const int MAX_SPAN = 14;

    public function __construct(
        private WorkspaceCalendar $calendar,
        private ComputeAnnualReport $compute,
        private MultiYearDetailedPeriods $detailed,
        private MultiYearSummary $summaryRow,
    ) {
    }

    public function __invoke(?string $fromParam, ?string $toParam, ?string $incompleteMonths): MultiYearReportView
    {
        $incomplete = null;
        if (null !== $incompleteMonths) {
            $incomplete = IncompleteMonths::tryFrom($incompleteMonths)
                ?? throw new InvalidMultiYearReportQuery('The incomplete-month setting is exclude or include.');
        }
        // Resolved once: the catalogue, the preferences and the first data month do not depend on
        // the year, so every year of the range reuses this instead of re-reading them.
        $resolved = $this->compute->resolve($incomplete?->value);
        $currentYear = (int) $this->calendar->today()->format('Y');
        $from = self::parseYear($fromParam, 'from');
        $to = self::parseYear($toParam, 'to');
        $firstYear = $resolved->firstDataMonth?->year;

        $to ??= $currentYear;
        if (null === $from) {
            // The default lower bound is the workspace's first data year, but it is also clamped to
            // the 15-year span: a long-lived workspace's default request must not itself exceed the
            // limit that an explicit `from` is refused for.
            $from = null === $firstYear
                ? $to
                : max($firstYear, $to - self::MAX_SPAN, self::FIRST_YEAR);
        }

        if ($from > $to || $from < self::FIRST_YEAR || $to > $currentYear) {
            throw new InvalidYearRange('A year range runs from 1900 to the current year, oldest year first.');
        }
        if ($to - $from > self::MAX_SPAN) {
            throw new YearRangeTooLarge('A year range spans at most 15 years.');
        }
        if (null === $firstYear) {
            return new MultiYearReportView($from, $to, null, false, $resolved->incomplete->value, [], [], [], AggregateQuality::EMPTY->value, []);
        }

        $computedByYear = [];
        $previousComputed = null;
        for ($year = max(self::FIRST_YEAR, $from - 1); $year <= $to; ++$year) {
            $computed = $this->compute->forYear($resolved, $year, $previousComputed);
            $computedByYear[$year] = $computed;
            $previousComputed = $computed;
        }

        $years = [];
        for ($year = $from; $year <= $to; ++$year) {
            $years[] = $this->year($year, $computedByYear);
        }

        $columns = $computedByYear[$to]->columns;
        $columnViews = array_map(
            static fn (AnnualColumn $column): AnnualColumnView => new AnnualColumnView($column->id, $column->label, $column->kind->value, AnnualColumnSeries::assetCode($column, $computedByYear[$to]->months), $column->institution, $column->parentLabel),
            $columns,
        );

        return new MultiYearReportView(
            $from,
            $to,
            $firstYear,
            false,
            $computedByYear[$to]->incomplete->value,
            $columnViews,
            $years,
            $this->summaryRow->summary($columns, $years, $computedByYear),
            self::quality($years),
            $this->detailed->detailedPeriod($columns, $computedByYear, $from, $to, $computedByYear[$to]->incomplete),
        );
    }

    /** @param array<int, ComputedAnnualYear> $computedByYear */
    private function year(int $year, array $computedByYear): MultiYearYearView
    {
        $computed = $computedByYear[$year];
        $previous = $computedByYear[$year - 1] ?? null;
        $state = self::yearState($computed->months);
        $previousState = null === $previous ? null : self::yearState($previous->months);
        $counted = self::countedMonths($computed->months);
        $snapshotMonths = 0;
        foreach ($computed->months as $month) {
            if ($month->snapshot) {
                ++$snapshotMonths;
            }
        }

        $cells = [];
        foreach ($computed->columns as $column) {
            $cells[$column->id] = $this->cell($column, $computed, $previous, $state, $previousState);
        }

        $flowCells = [];
        $assetCode = null;
        foreach (['cashIncome', 'budgetExpenses', 'budgetSurplus'] as $id) {
            $column = $computed->catalogue->column($id);
            assert(null !== $column);
            $aggregate = $computed->aggregates[$id][1] ?? AnnualColumnSeries::aggregate($column, $computed->months, $computed->incomplete);
            $assetCode ??= $aggregate->asset?->toString();
            $flowCells[$id] = new AnnualCellView($aggregate->total?->toString(), $aggregate->totalReason?->value);
        }

        return new MultiYearYearView($year, $state, $counted, $snapshotMonths, $counted - $snapshotMonths, MultiYearMetricPolicyView::of($computed->policy), $cells, new MultiYearFlowsView($assetCode, $flowCells['cashIncome'], $flowCells['budgetExpenses'], $flowCells['budgetSurplus']));
    }

    private function cell(AnnualColumn $column, ComputedAnnualYear $computed, ?ComputedAnnualYear $previous, string $state, ?string $previousState): MultiYearCellView
    {
        [$value, $reason, $asOf] = $this->summaryRow->figureFor($column, $computed);
        $previousValue = null;
        $previousReason = null;
        if (null !== $previous) {
            [$previousValue, $previousReason] = $this->summaryRow->figureFor($column, $previous);
        }
        [$change, $changeRatio, $changeReason] = MultiYearSummary::change(
            $column->kind, $value, $reason, $previousValue, $previousReason, $state, $previousState, $computed->policy, $previous?->policy,
        );

        return new MultiYearCellView($value?->toString(), $reason, $asOf, $change, $changeRatio, $changeReason);
    }

    /** @param list<AnnualMonth> $months */
    private static function yearState(array $months): string
    {
        $counted = self::countedMonths($months);
        if (0 === $counted) {
            return 'NO_DATA';
        }
        foreach ($months as $month) {
            if (MonthState::COMPLETE !== $month->state) {
                return 'PARTIAL';
            }
        }

        return 'COMPLETE';
    }

    /** @param list<AnnualMonth> $months */
    private static function countedMonths(array $months): int
    {
        $counted = 0;
        foreach ($months as $month) {
            if (MonthState::COMPLETE === $month->state || MonthState::PROVISIONAL === $month->state) {
                ++$counted;
            }
        }

        return $counted;
    }

    /** @param list<MultiYearYearView> $years */
    private static function quality(array $years): string
    {
        if ([] === $years) {
            return AggregateQuality::EMPTY->value;
        }
        $allComplete = true;
        $anyData = false;
        foreach ($years as $year) {
            $allComplete = $allComplete && 'COMPLETE' === $year->state;
            $anyData = $anyData || 'NO_DATA' !== $year->state;
        }

        return match (true) {
            !$anyData => AggregateQuality::EMPTY->value,
            $allComplete => AggregateQuality::COMPLETE->value,
            default => AggregateQuality::PARTIAL->value,
        };
    }

    private static function parseYear(?string $value, string $name): ?int
    {
        if (null === $value) {
            return null;
        }
        if (1 !== preg_match('/^\d{1,9}$/', $value)) {
            throw new InvalidMultiYearReportQuery(sprintf('The %s year is a plain integer.', $name));
        }

        return (int) $value;
    }
}
