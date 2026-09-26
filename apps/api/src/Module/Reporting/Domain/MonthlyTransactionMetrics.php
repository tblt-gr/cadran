<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain;

final readonly class MonthlyTransactionMetrics
{
    public function __construct(
        public MonthlyMetric $cashIncome,
        public MonthlyMetric $nonCashBenefits,
        public MonthlyMetric $benefitSpending,
        public MonthlyMetric $budgetExpenses,
        public MonthlyMetric $uncategorizedExpenses,
        public MonthlyMetric $budgetSurplus,
        public MonthlyMetric $savingsTransfers,
        public MonthlyMetric $cashSavingsRate,
    ) {
    }
}
