<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

/** Why one account raises a closing condition; each reason has its own fix. */
enum PeriodClosingReason: string
{
    case NO_BALANCE_IN_MONTH = 'NO_BALANCE_IN_MONTH';
    case BALANCE_NOT_RECONCILED = 'BALANCE_NOT_RECONCILED';
    case DISCREPANCY = 'DISCREPANCY';
    case NO_MONTH_END_VALUATION = 'NO_MONTH_END_VALUATION';
    case VALUATION_NOT_CALCULATED = 'VALUATION_NOT_CALCULATED';
}
