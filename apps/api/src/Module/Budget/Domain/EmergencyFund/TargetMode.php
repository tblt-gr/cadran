<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\EmergencyFund;

/** How the amount the fund must hold is set: months of essential expenses, a fixed amount, or a share of the income. */
enum TargetMode: string
{
    case MONTHS = 'MONTHS';
    case AMOUNT = 'AMOUNT';
    case INCOME_RATIO = 'INCOME_RATIO';
}
