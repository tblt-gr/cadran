<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Accounts\Application\NetWorthView;

final readonly class MonthlyProjectionView
{
    /**
     * @param list<MonthlyAccountView>         $accounts
     * @param array<string, MonthlyMetricView> $expensesByAxis           budget expenses seen through each
     *                                                                   analytic axis; a split carrying two
     *                                                                   axes counts in both, so the axes do
     *                                                                   not add up to budget expenses
     * @param list<MonthlyRecapCategoryView>   $categoryMetrics
     * @param list<string>                     $budgetExpenseCategoryIds expense categories counted in the budget when the month was read
     */
    public function __construct(
        public string $month,
        public string $periodStart,
        public string $periodEnd,
        public string $netWorthComparedOn,
        public string $netWorthAsOf,
        public bool $provisional,
        public string $state,
        public string $quality,
        public int $pendingCount,
        public MonthlyMetricView $cashIncome,
        public MonthlyMetricView $nonCashBenefits,
        public MonthlyMetricView $benefitSpending,
        public MonthlyMetricView $budgetExpenses,
        public MonthlyMetricView $uncategorizedExpenses,
        public MonthlyMetricView $budgetSurplus,
        public MonthlyMetricView $savingsTransfers,
        public MonthlyMetricView $cashSavingsRate,
        public MonthlyMetricView $savingsInflows,
        public MonthlyMetricView $savingsWithdrawals,
        public MonthlyMetricView $netSavingsTransfers,
        public MonthlyMetricView $netSavingsRate,
        public MonthlyMetricView $beginningNetWorth,
        public MonthlyMetricView $endNetWorth,
        public MonthlyMetricView $netWorthDelta,
        public string $beginningNetWorthState,
        public array $accounts,
        public string $reconciliationStatus,
        public array $expensesByAxis,
        public array $categoryMetrics,
        public NetWorthView $netWorth,
        public MetricPolicyReference $metricPolicy,
        public array $budgetExpenseCategoryIds = [],
    ) {
    }
}
