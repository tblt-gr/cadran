<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reporting\Domain\Aggregation\Aggregate;
use App\Module\Reporting\Domain\Aggregation\IncompleteMonths;

/**
 * What one year of the annual report computes before any month-row or chart assembly: the
 * resolved months, the metric policy and the aggregate of every selected column. Both
 * {@see BuildAnnualReport} and {@see ReadMultiYearReport} read this so the two reports never carry
 * two formulas for the same figure.
 */
final readonly class ComputedAnnualYear
{
    /**
     * @param list<AnnualColumn>                                   $columns
     * @param list<AnnualMonth>                                    $months     January to December
     * @param list<AnnualMonth>                                    $previous   the year before, January to December
     * @param array<string, array{AnnualAggregateView, Aggregate}> $aggregates by column id
     */
    public function __construct(
        public int $year,
        public WorkspaceScope $workspace,
        public IncompleteMonths $incomplete,
        public AnnualCatalogue $catalogue,
        public array $columns,
        public array $months,
        public array $previous,
        public AnnualPolicyView $policy,
        public array $aggregates,
    ) {
    }
}
