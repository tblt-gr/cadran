<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\EmergencyFund;

/** Which complete months the essential-expense average reads, anchored on the as-of month. */
enum ReferenceMode: string
{
    case PREVIOUS_YEAR = 'PREVIOUS_YEAR';
    case TRAILING_MONTHS = 'TRAILING_MONTHS';
}
