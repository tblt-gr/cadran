<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reporting\Domain\Aggregation\Aggregate;
use App\Module\Reporting\Domain\Aggregation\AggregateQuality;
use App\Module\Reporting\Domain\Aggregation\AggregateReason;
use App\Module\Reporting\Domain\Aggregation\IncompleteMonths;
use App\Module\Reporting\Domain\Aggregation\MonthState;
use App\Module\Reporting\Domain\Aggregation\ReportAggregator;
use App\Module\Reporting\Domain\MetricPolicyRepository;
use App\Module\Transactions\Application\ReadFirstTransactionMonth;

/**
 * The annual monthly-summary report: one row per month, the aggregates of the selected columns and
 * the two chart datasets. Every figure comes from a monthly projection or its closed-month
 * snapshot; nothing here changes a formula.
 */
final readonly class BuildAnnualReport
{
    public const int FIRST_YEAR = 1900;

    public function __construct(
        private CallerWorkspace $caller,
        private WorkspaceCalendar $calendar,
        private ReadAnnualPreferences $preferences,
        private ReadAnnualCatalogue $catalogue,
        private ReadFirstTransactionMonth $firstTransactionMonth,
        private LoadAnnualMonths $loadMonths,
        private ResolveMetricPolicy $policies,
        private MetricPolicyRepository $policyRepository,
    ) {
    }

    public function __invoke(int $year, ?string $incompleteMonths): AnnualReportView
    {
        $context = $this->context($year, $incompleteMonths);
        $stored = $this->preferences->stored();
        $incomplete = $context->incomplete ?? $stored->incompleteMonths;
        $catalogue = ($this->catalogue)($context->workspace);
        $columns = [];
        foreach ($stored->columns as $id) {
            $column = $catalogue->column($id);
            if (null !== $column) {
                $columns[] = $column;
            }
        }

        $months = ($this->loadMonths)($context->workspace, $year, $context->firstDataMonth);
        $previous = $year > self::FIRST_YEAR ? ($this->loadMonths)($context->workspace, $year - 1, $context->firstDataMonth) : [];
        $policy = $this->policy($context->workspace, $year, $months);
        $columnViews = [];
        $aggregates = [];
        foreach ($columns as $column) {
            $columnViews[] = new AnnualColumnView($column->id, $column->label, $column->kind->value, AnnualColumnSeries::assetCode($column, $months));
            $aggregates[$column->id] = $this->aggregate($column, $months, $previous, $incomplete, $policy);
        }
        $rows = [];
        foreach ($months as $month) {
            $cells = [];
            foreach ($columns as $column) {
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
        );
    }

    public function explain(int $year, string $columnId, ?string $incompleteMonths): AnnualColumnExplanationView
    {
        $context = $this->context($year, $incompleteMonths);
        $incomplete = $context->incomplete ?? $this->preferences->stored()->incompleteMonths;
        $column = ($this->catalogue)($context->workspace)->column($columnId);
        if (null === $column || !$column->known) {
            throw new AnnualColumnNotFound('This workspace has no such annual report column.');
        }

        $months = ($this->loadMonths)($context->workspace, $year, $context->firstDataMonth);
        $previous = $year > self::FIRST_YEAR ? ($this->loadMonths)($context->workspace, $year - 1, $context->firstDataMonth) : [];
        $policy = $this->policy($context->workspace, $year, $months);
        [$view, $aggregate] = $this->aggregate($column, $months, $previous, $incomplete, $policy);

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

    private function context(int $year, ?string $incompleteMonths): AnnualContext
    {
        $workspace = $this->caller->resolve();
        if ($year < self::FIRST_YEAR || $year > (int) $this->calendar->today()->format('Y')) {
            throw new InvalidReportYear('A report year lies between 1900 and the current year of the workspace.');
        }
        $incomplete = null;
        if (null !== $incompleteMonths) {
            $incomplete = IncompleteMonths::tryFrom($incompleteMonths)
                ?? throw new InvalidAnnualReportQuery('The incomplete-month setting is exclude or include.');
        }
        $first = ($this->firstTransactionMonth)($workspace);

        return new AnnualContext($workspace, $incomplete, null === $first ? null : CalendarMonth::fromString($first));
    }

    /**
     * @param list<AnnualMonth> $months
     * @param list<AnnualMonth> $previous
     *
     * @return array{AnnualAggregateView, Aggregate}
     */
    private function aggregate(AnnualColumn $column, array $months, array $previous, IncompleteMonths $incomplete, AnnualPolicyView $policy): array
    {
        $current = AnnualColumnSeries::aggregate($column, $months, $incomplete);
        $before = AnnualColumnSeries::aggregate($column, $previous, $incomplete);
        $mismatch = ReportAggregator::compareAveragesReason($current, $before);
        [$average, $reason] = match (true) {
            AnnualPolicyView::MIXED === $policy->state => [null, AggregateReason::MIXED_METRIC_POLICIES->value],
            null !== $mismatch => [null, $mismatch->value],
            default => [$before->average?->toString(), $before->averageReason?->value],
        };

        return [AnnualAggregateView::of($current, $average, $reason), $current];
    }

    /** @param list<AnnualMonth> $months */
    private function policy(WorkspaceScope $workspace, int $year, array $months): AnnualPolicyView
    {
        $versions = [];
        foreach ($months as $month) {
            if (null !== $month->figures) {
                $versions[$month->figures->policyVersion] = $month->figures->policyVersion;
            }
        }
        sort($versions);
        if (count($versions) > 1) {
            return new AnnualPolicyView(AnnualPolicyView::MIXED, null, null, $versions);
        }
        $version = $versions[0] ?? ($this->policies)($workspace, new CalendarMonth($year, 12))->version;

        return new AnnualPolicyView(
            AnnualPolicyView::SINGLE,
            $version,
            $this->policyRepository->find($workspace, $version)->label ?? MetricPolicyReference::UNKNOWN_LABEL,
            [$version],
        );
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
