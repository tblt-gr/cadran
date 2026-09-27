<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Reporting\Domain\Aggregation\Aggregate;
use App\Module\Reporting\Domain\Aggregation\AggregateQuality;
use App\Module\Reporting\Domain\Aggregation\AggregateReason;
use App\Module\Reporting\Domain\Aggregation\ColumnKind;
use App\Module\Reporting\Domain\Aggregation\IncompleteMonths;
use App\Module\Reporting\Domain\Aggregation\MonthState;
use App\Module\Reporting\Domain\Aggregation\ResponseRateAggregator;

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
            return new MultiYearReportView($from, $to, null, false, $resolved->incomplete->value, [], [], [], AggregateQuality::EMPTY->value);
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
            static fn (AnnualColumn $column): AnnualColumnView => new AnnualColumnView($column->id, $column->label, $column->kind->value, AnnualColumnSeries::assetCode($column, $computedByYear[$to]->months)),
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
            $this->summary($columns, $years, $computedByYear),
            self::quality($years),
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

        return new MultiYearYearView($year, $state, $counted, $snapshotMonths, $counted - $snapshotMonths, MultiYearMetricPolicyView::of($computed->policy), $cells);
    }

    private function cell(AnnualColumn $column, ComputedAnnualYear $computed, ?ComputedAnnualYear $previous, string $state, ?string $previousState): MultiYearCellView
    {
        [$value, $reason, $asOf] = $this->figureFor($column, $computed);
        $previousValue = null;
        $previousReason = null;
        if (null !== $previous) {
            [$previousValue, $previousReason] = $this->figureFor($column, $previous);
        }
        [$change, $changeRatio, $changeReason] = self::change(
            $column->kind, $value, $reason, $previousValue, $previousReason, $state, $previousState, $computed->policy, $previous?->policy,
        );

        return new MultiYearCellView($value?->toString(), $reason, $asOf, $change, $changeRatio, $changeReason);
    }

    /**
     * @param list<AnnualColumn>             $columns
     * @param list<MultiYearYearView>        $years
     * @param array<int, ComputedAnnualYear> $computedByYear
     *
     * @return array<string, MultiYearSummaryCellView>
     */
    private function summary(array $columns, array $years, array $computedByYear): array
    {
        $completeYears = array_values(array_filter($years, static fn (MultiYearYearView $year): bool => 'COMPLETE' === $year->state));
        $summary = [];
        foreach ($columns as $column) {
            $summary[$column->id] = $this->summaryCell($column, $completeYears, $computedByYear);
        }

        return $summary;
    }

    /**
     * @param list<MultiYearYearView>        $completeYears
     * @param array<int, ComputedAnnualYear> $computedByYear
     */
    private function summaryCell(AnnualColumn $column, array $completeYears, array $computedByYear): MultiYearSummaryCellView
    {
        if ([] === $completeYears) {
            return new MultiYearSummaryCellView(null, AggregateReason::EMPTY_POPULATION->value, null, null);
        }

        $versions = [];
        foreach ($completeYears as $year) {
            $policy = $computedByYear[$year->year]->policy;
            if (AnnualPolicyView::MIXED === $policy->state) {
                // A year that is itself internally mixed (the policy changed mid-year) can never
                // agree with another year's single version, whatever that version is.
                return new MultiYearSummaryCellView(null, AggregateReason::MIXED_METRIC_POLICIES->value, null, null);
            }
            $versions[] = $policy->version;
        }
        if (count(array_unique($versions)) > 1) {
            return new MultiYearSummaryCellView(null, AggregateReason::MIXED_METRIC_POLICIES->value, null, null);
        }

        /** @var array<int, ?DecimalValue> $perYear */
        $perYear = [];
        foreach ($completeYears as $year) {
            [$value] = $this->figureFor($column, $computedByYear[$year->year]);
            $perYear[$year->year] = $value;
        }

        /** @var array{int, DecimalValue}|null $minimum */
        $minimum = null;
        /** @var array{int, DecimalValue}|null $maximum */
        $maximum = null;
        foreach ($perYear as $year => $value) {
            if (null === $value) {
                continue;
            }
            if (null === $minimum || $value->compareTo($minimum[1]) < 0) {
                $minimum = [$year, $value];
            }
            if (null === $maximum || $value->compareTo($maximum[1]) > 0) {
                $maximum = [$year, $value];
            }
        }
        if (ColumnKind::RATE === $column->kind) {
            $allMonths = [];
            foreach ($completeYears as $year) {
                foreach ($computedByYear[$year->year]->months as $month) {
                    $allMonths[] = $month;
                }
            }
            [$average, $reason] = ResponseRateAggregator::rateOfSums(
                AnnualColumnSeries::rateInputs($column, $allMonths),
                IncompleteMonths::EXCLUDE,
            );

            return new MultiYearSummaryCellView($average, $reason?->value, self::extreme($minimum), self::extreme($maximum));
        }

        // A complete year whose own cell is unavailable for this column (e.g. an account not yet
        // open) is not a broken figure to null the whole average over: it is simply outside that
        // column's population, the same way a month before an account opens never enters a monthly
        // aggregate. The mean is taken over whichever complete years do carry a value.
        $present = array_values(array_filter($perYear, static fn (?DecimalValue $value): bool => null !== $value));
        if ([] === $present) {
            return new MultiYearSummaryCellView(null, AggregateReason::EMPTY_POPULATION->value, self::extreme($minimum), self::extreme($maximum));
        }

        $sum = '0';
        foreach ($present as $value) {
            $sum = ExactDecimal::addForResponse($sum, $value->toString());
        }
        $average = ExactDecimal::divideForResponse($sum, (string) count($present));

        return new MultiYearSummaryCellView($average, null, self::extreme($minimum), self::extreme($maximum));
    }

    /** @param array{int, DecimalValue}|null $found */
    private static function extreme(?array $found): ?MultiYearExtremeView
    {
        return null === $found ? null : new MultiYearExtremeView($found[1]->toString(), $found[0]);
    }

    /** @return array{?DecimalValue, ?string, ?string} value, reason, the STOCK period-end month */
    private function figureFor(AnnualColumn $column, ComputedAnnualYear $computed): array
    {
        $aggregate = $computed->aggregates[$column->id][1];
        [$value, $reason, $asOf] = ColumnKind::STOCK === $column->kind
            ? self::stockFigure($aggregate)
            : [$aggregate->total, $aggregate->totalReason?->value, null];

        if (str_starts_with($column->id, 'account:')) {
            $openedOn = $computed->catalogue->accountOpenedOn(substr($column->id, 8));
            $yearEnd = (new CalendarMonth($computed->year, 12))->lastDay()->format('Y-m-d');
            if (null !== $openedOn && $openedOn > $yearEnd) {
                return [null, AggregateReason::ACCOUNT_NOT_OPEN->value, null];
            }
        }

        return [$value, $reason, $asOf];
    }

    /** @return array{?DecimalValue, ?string, ?string} */
    private static function stockFigure(Aggregate $aggregate): array
    {
        $periodEnd = $aggregate->periodEnd;

        return match (true) {
            null === $periodEnd => [null, $aggregate->totalReason?->value, null],
            null === $periodEnd->value => [null, $periodEnd->reason, null],
            default => [$periodEnd->value, null, $periodEnd->month->key()],
        };
    }

    /** @return array{?string, ?string, ?string} change, changeRatio, changeReason */
    private static function change(
        ColumnKind $kind,
        ?DecimalValue $value,
        ?string $reason,
        ?DecimalValue $previousValue,
        ?string $previousReason,
        string $state,
        ?string $previousState,
        AnnualPolicyView $policy,
        ?AnnualPolicyView $previousPolicy,
    ): array {
        if ('COMPLETE' !== $state || 'COMPLETE' !== $previousState) {
            return [null, null, AggregateReason::PARTIAL_YEAR->value];
        }
        $mismatch = null === $previousPolicy
            || AnnualPolicyView::MIXED === $policy->state
            || AnnualPolicyView::MIXED === $previousPolicy->state
            || $policy->version !== $previousPolicy->version;
        if ($mismatch) {
            return [null, null, AggregateReason::POLICY_MISMATCH->value];
        }
        if (null === $value || null === $previousValue) {
            // Both years are otherwise COMPLETE and share a policy, but the cell itself carries its
            // own reason (e.g. ACCOUNT_NOT_OPEN, ZERO_DENOMINATOR): surface that reason rather than
            // hiding it behind a generic PARTIAL_YEAR.
            return [null, null, $reason ?? $previousReason ?? AggregateReason::PARTIAL_YEAR->value];
        }

        $change = ExactDecimal::subtractForResponse($value->toString(), $previousValue->toString());
        if (ColumnKind::RATE === $kind) {
            return [$change, null, null];
        }
        if (ExactDecimal::isZero($previousValue)) {
            return [$change, null, AggregateReason::ZERO_BASE->value];
        }
        if ($previousValue->isNegative()) {
            return [$change, null, AggregateReason::NEGATIVE_BASE->value];
        }

        return [$change, ExactDecimal::divideForResponse($change, ExactDecimal::absolute($previousValue)->toString()), null];
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
