<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reporting\Domain\Aggregation\Aggregate;
use App\Module\Reporting\Domain\Aggregation\AggregateReason;
use App\Module\Reporting\Domain\Aggregation\IncompleteMonths;
use App\Module\Reporting\Domain\Aggregation\ReportAggregator;
use App\Module\Reporting\Domain\MetricPolicyRepository;

/**
 * The per-year computation shared by the annual report and the multi-year report: resolves the
 * calling workspace and its months, the metric policy of the year and the aggregate of every
 * column the workspace has selected. Neither report defines its own formula on top of this.
 */
final readonly class ComputeAnnualReport
{
    public const int FIRST_YEAR = BuildAnnualReport::FIRST_YEAR;

    public function __construct(
        private CallerWorkspace $caller,
        private WorkspaceCalendar $calendar,
        private ReadAnnualPreferences $preferences,
        private ReadAnnualCatalogue $catalogue,
        private ReadFirstDataMonth $firstDataMonth,
        private LoadAnnualMonths $loadMonths,
        private ResolveMetricPolicy $policies,
        private MetricPolicyRepository $policyRepository,
    ) {
    }

    public function __invoke(int $year, ?string $incompleteMonths): ComputedAnnualYear
    {
        $this->assertYear($year);

        return $this->forYear($this->resolve($incompleteMonths), $year);
    }

    /**
     * Resolves everything about the calling workspace that does not depend on a single year, so a
     * caller that reads several years, {@see ReadMultiYearReport}, reads the catalogue, the
     * preferences and the first data month once and reuses the result across every {@see forYear()}
     * call, instead of re-reading them once per year.
     */
    public function resolve(?string $incompleteMonths): ResolvedAnnualReportContext
    {
        $workspace = $this->caller->resolve();
        $override = null;
        if (null !== $incompleteMonths) {
            $override = IncompleteMonths::tryFrom($incompleteMonths)
                ?? throw new InvalidAnnualReportQuery('The incomplete-month setting is exclude or include.');
        }
        $stored = $this->preferences->stored();
        $catalogue = ($this->catalogue)($workspace);
        $columns = [];
        foreach ($stored->columns as $id) {
            $column = $catalogue->column($id);
            if (null !== $column) {
                $columns[] = $column;
            }
        }
        $first = ($this->firstDataMonth)($workspace);

        return new ResolvedAnnualReportContext(
            $workspace,
            $override ?? $stored->incompleteMonths,
            $catalogue,
            $columns,
            null === $first ? null : CalendarMonth::fromString($first),
        );
    }

    /**
     * A caller that walks a range oldest-first, such as {@see ReadMultiYearReport}, passes the year
     * it computed just before as `$previousComputed`: its months are reused as this year's
     * `previous` instead of loading them from the database a second time.
     */
    public function forYear(ResolvedAnnualReportContext $resolved, int $year, ?ComputedAnnualYear $previousComputed = null): ComputedAnnualYear
    {
        $this->assertYear($year);
        $months = ($this->loadMonths)($resolved->workspace, $year, $resolved->firstDataMonth);
        $previous = match (true) {
            $year <= self::FIRST_YEAR => [],
            null !== $previousComputed && $year - 1 === $previousComputed->year => $previousComputed->months,
            default => ($this->loadMonths)($resolved->workspace, $year - 1, $resolved->firstDataMonth),
        };
        $policy = $this->policy($resolved->workspace, $year, $months);
        $aggregates = [];
        foreach ($resolved->columns as $column) {
            $aggregates[$column->id] = $this->aggregate($column, $months, $previous, $resolved->incomplete, $policy);
        }

        return new ComputedAnnualYear($year, $resolved->incomplete, $resolved->catalogue, $resolved->columns, $months, $previous, $policy, $aggregates);
    }

    private function assertYear(int $year): void
    {
        if ($year < self::FIRST_YEAR || $year > (int) $this->calendar->today()->format('Y')) {
            throw new InvalidReportYear('A report year lies between 1900 and the current year of the workspace.');
        }
    }

    public function catalogue(WorkspaceScope $workspace): AnnualCatalogue
    {
        return ($this->catalogue)($workspace);
    }

    /** @return list<AnnualMonth> January to December */
    public function months(WorkspaceScope $workspace, int $year, ?CalendarMonth $firstDataMonth): array
    {
        return ($this->loadMonths)($workspace, $year, $firstDataMonth);
    }

    public function defaultIncompleteMonths(): IncompleteMonths
    {
        return $this->preferences->stored()->incompleteMonths;
    }

    public function context(int $year, ?string $incompleteMonths): AnnualContext
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
        $first = ($this->firstDataMonth)($workspace);

        return new AnnualContext($workspace, $incomplete, null === $first ? null : CalendarMonth::fromString($first));
    }

    /**
     * @param list<AnnualMonth> $months
     * @param list<AnnualMonth> $previous
     *
     * @return array{AnnualAggregateView, Aggregate}
     */
    public function aggregate(AnnualColumn $column, array $months, array $previous, IncompleteMonths $incomplete, AnnualPolicyView $policy): array
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
    public function policy(WorkspaceScope $workspace, int $year, array $months): AnnualPolicyView
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
}
