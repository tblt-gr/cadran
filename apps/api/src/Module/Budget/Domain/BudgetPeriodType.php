<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain;

enum BudgetPeriodType: string
{
    case MONTH = 'MONTH';
    case YEAR = 'YEAR';
}
