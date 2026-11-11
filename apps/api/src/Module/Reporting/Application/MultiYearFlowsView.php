<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

/** Annual chart figures are independent of the columns selected for the report table. */
final readonly class MultiYearFlowsView
{
    public function __construct(
        public ?string $assetCode,
        public AnnualCellView $cashIncome,
        public AnnualCellView $budgetExpenses,
        public AnnualCellView $budgetSurplus,
    ) {
    }
}
