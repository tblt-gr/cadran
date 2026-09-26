<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain;

/** How a metric policy derives the cash savings rate; one allowed value in this release. */
enum SavingsRateFormula: string
{
    case BUDGET_SURPLUS_OVER_CASH_INCOME = 'BUDGET_SURPLUS_OVER_CASH_INCOME';
}
