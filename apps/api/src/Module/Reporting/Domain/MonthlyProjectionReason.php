<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain;

/** Why one monthly projection figure could not be produced. */
enum MonthlyProjectionReason: string
{
    case MIXED_ASSETS = 'MIXED_ASSETS';
    case NO_ACCOUNT = 'NO_ACCOUNT';
    case ZERO_CASH_INCOME = 'ZERO_CASH_INCOME';
    case MISSING_BENEFIT_SOURCE = 'MISSING_BENEFIT_SOURCE';
}
