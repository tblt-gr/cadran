<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain;

enum BudgetComparisonReason: string
{
    case MISSING_TARGET = 'MISSING_TARGET';
    case MIXED_ASSETS = 'MIXED_ASSETS';
    case NO_ACCOUNT = 'NO_ACCOUNT';
    case ZERO_CASH_INCOME = 'ZERO_CASH_INCOME';
}
