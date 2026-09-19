<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

final readonly class MonthlyProjectionView
{
    /** @param list<MonthlyAccountView> $accounts */
    public function __construct(
        public string $month,
        public string $periodStart,
        public string $periodEnd,
        public string $state,
        public string $quality,
        public int $pendingCount,
        public MonthlyMetricView $cashIncome,
        public MonthlyMetricView $nonCashBenefits,
        public MonthlyMetricView $budgetExpenses,
        public MonthlyMetricView $uncategorizedExpenses,
        public MonthlyMetricView $budgetSurplus,
        public MonthlyMetricView $savingsTransfers,
        public MonthlyMetricView $cashSavingsRate,
        public MonthlyMetricView $beginningNetWorth,
        public MonthlyMetricView $endNetWorth,
        public MonthlyMetricView $netWorthDelta,
        public string $beginningNetWorthState,
        public array $accounts,
        public string $reconciliationStatus,
    ) {
    }
}
