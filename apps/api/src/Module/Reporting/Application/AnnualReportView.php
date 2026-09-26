<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

final readonly class AnnualReportView
{
    /**
     * @param list<AnnualColumnView>             $columns
     * @param list<AnnualRowView>                $rows
     * @param array<string, AnnualAggregateView> $aggregates by column id
     */
    public function __construct(
        public int $year,
        public string $today,
        public string $incompleteMonths,
        public AnnualPolicyView $metricPolicy,
        public array $columns,
        public array $rows,
        public array $aggregates,
        public AnnualTopCategoriesView $topExpenseCategories,
        public AnnualAllocationView $allocation,
        public AnnualFlowsView $flows,
        public AnnualNetWorthView $netWorth,
        public string $quality,
    ) {
    }
}
