<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Reporting\Domain\Aggregation\AggregateQuality;
use App\Module\Reporting\Domain\Aggregation\MonthState;

/**
 * The annual monthly-summary report: one row per month, the aggregates of the selected columns and
 * the two chart datasets. Every figure comes from a monthly projection or its closed-month
 * snapshot; nothing here changes a formula: the resolution of months, policy and column
 * aggregates itself lives in {@see ComputeAnnualReport}, shared with the multi-year report.
 */
final readonly class BuildAnnualReport
{
    public const int FIRST_YEAR = 1900;

    public function __construct(
        private ComputeAnnualReport $compute,
        private WorkspaceCalendar $calendar,
    ) {
    }

    public function __invoke(int $year, ?string $incompleteMonths): AnnualReportView
    {
        $computed = ($this->compute)($year, $incompleteMonths);
        $catalogue = $computed->catalogue;
        $months = $computed->months;
        $previous = $computed->previous;
        $incomplete = $computed->incomplete;
        $policy = $computed->policy;
        $columnViews = [];
        foreach ($computed->columns as $column) {
            $columnViews[] = new AnnualColumnView($column->id, $column->label, $column->kind->value, AnnualColumnSeries::assetCode($column, $months));
        }
        $aggregates = $computed->aggregates;
        $rows = [];
        foreach ($months as $month) {
            $cells = [];
            foreach ($computed->columns as $column) {
                $cells[$column->id] = AnnualColumnSeries::cell($column, $month);
            }
            $rows[] = new AnnualRowView(
                $month->month->key(),
                $month->state->value,
                $month->closed,
                $month->snapshot,
                $month->figures?->policyVersion,
                null === $month->figures ? 0 : $month->figures->pendingCount,
                $cells,
            );
        }
        $budgetExpenses = AnnualColumnSeries::aggregate($catalogue->column('budgetExpenses') ?? throw new \LogicException('The budget expenses column is fixed.'), $months, $incomplete);

        return new AnnualReportView(
            $year,
            $this->calendar->today()->format('Y-m-d'),
            $incomplete->value,
            $policy,
            $columnViews,
            $rows,
            array_map(static fn (array $result): AnnualAggregateView => $result[0], $aggregates),
            AnnualCharts::topExpenseCategories($months, $catalogue, $budgetExpenses),
            AnnualCharts::allocation($months, $incomplete, $catalogue),
            AnnualCharts::flows($months),
            AnnualCharts::netWorth($months),
            self::quality($months),
            self::hasData($previous),
        );
    }

    public function explain(int $year, string $columnId, ?string $incompleteMonths): AnnualColumnExplanationView
    {
        $context = $this->compute->context($year, $incompleteMonths);
        $incomplete = $context->incomplete ?? $this->compute->defaultIncompleteMonths();
        $column = $this->compute->catalogue($context->workspace)->column($columnId);
        if (null === $column || !$column->known) {
            throw new AnnualColumnNotFound('This workspace has no such annual report column.');
        }

        $months = $this->compute->months($context->workspace, $year, $context->firstDataMonth);
        $previous = $year > self::FIRST_YEAR ? $this->compute->months($context->workspace, $year - 1, $context->firstDataMonth) : [];
        $policy = $this->compute->policy($context->workspace, $year, $months);
        [$view, $aggregate] = $this->compute->aggregate($column, $months, $previous, $incomplete, $policy);

        $counted = array_flip(array_map(static fn (CalendarMonth $month): string => $month->key(), $aggregate->countedMonths));
        $excluded = [];
        foreach ($aggregate->excludedMonths as $item) {
            $excluded[$item->month->key()] = $item->reason->value;
        }
        $explained = [];
        foreach ($months as $month) {
            $cell = AnnualColumnSeries::cell($column, $month);
            $key = $month->month->key();
            $explained[] = new AnnualMonthExplanationView($key, $month->state->value, $cell->value, $cell->reason, isset($counted[$key]), $excluded[$key] ?? null);
        }

        return new AnnualColumnExplanationView($column->id, $column->kind->value, $aggregate->formula, $policy, $explained, $view);
    }

    /** @param list<AnnualMonth> $months */
    private static function hasData(array $months): bool
    {
        foreach ($months as $month) {
            if (MonthState::NO_DATA !== $month->state && MonthState::FUTURE !== $month->state) {
                return true;
            }
        }

        return false;
    }

    /** @param list<AnnualMonth> $months */
    private static function quality(array $months): string
    {
        $computed = false;
        $partial = false;
        $provisional = false;
        foreach ($months as $month) {
            $computed = $computed || null !== $month->figures;
            $partial = $partial || MonthState::NO_DATA === $month->state || 'MISSING' === $month->figures?->quality;
            $provisional = $provisional || MonthState::PROVISIONAL === $month->state;
        }

        return match (true) {
            !$computed => AggregateQuality::EMPTY->value,
            $partial => AggregateQuality::PARTIAL->value,
            $provisional => AggregateQuality::PROVISIONAL->value,
            default => AggregateQuality::COMPLETE->value,
        };
    }
}
