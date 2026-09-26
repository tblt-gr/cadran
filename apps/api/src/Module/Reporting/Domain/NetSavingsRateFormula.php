<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain;

/** How a metric policy derives the net savings rate; one allowed value in this release. */
enum NetSavingsRateFormula: string
{
    case NET_SAVINGS_TRANSFERS_OVER_CASH_INCOME = 'NET_SAVINGS_TRANSFERS_OVER_CASH_INCOME';
}
