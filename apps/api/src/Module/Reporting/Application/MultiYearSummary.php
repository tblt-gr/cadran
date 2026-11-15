<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Reporting\Domain\Aggregation\Aggregate;
use App\Module\Reporting\Domain\Aggregation\AggregateReason;
use App\Module\Reporting\Domain\Aggregation\ColumnKind;
use App\Module\Reporting\Domain\Aggregation\IncompleteMonths;
use App\Module\Reporting\Domain\Aggregation\ResponseRateAggregator;

/**
 * The summary row of the all-years report and the per-year figures and year-over-year change it shares with the year rows.
 */
final readonly class MultiYearSummary
{
    /**
     * @param list<AnnualColumn>             $columns
     * @param list<MultiYearYearView>        $years
     * @param array<int, ComputedAnnualYear> $computedByYear
     *
     * @return array<string, MultiYearSummaryCellView>
     */
    public function summary(array $columns, array $years, array $computedByYear): array
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
    public function summaryCell(AnnualColumn $column, array $completeYears, array $computedByYear): MultiYearSummaryCellView
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
    public static function extreme(?array $found): ?MultiYearExtremeView
    {
        return null === $found ? null : new MultiYearExtremeView($found[1]->toString(), $found[0]);
    }

    /** @return array{?DecimalValue, ?string, ?string} value, reason, the STOCK period-end month */
    public function figureFor(AnnualColumn $column, ComputedAnnualYear $computed): array
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
    public static function stockFigure(Aggregate $aggregate): array
    {
        $periodEnd = $aggregate->periodEnd;

        return match (true) {
            null === $periodEnd => [null, $aggregate->totalReason?->value, null],
            null === $periodEnd->value => [null, $periodEnd->reason, null],
            default => [$periodEnd->value, null, $periodEnd->month->key()],
        };
    }

    /** @return array{?string, ?string, ?string} change, changeRatio, changeReason */
    public static function change(
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
}
