<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

final readonly class AnnualFlowMonthView
{
    public function __construct(
        public string $month,
        public AnnualCellView $cashIncome,
        public AnnualCellView $budgetExpenses,
        public AnnualCellView $budgetSurplus,
    ) {
    }
}
