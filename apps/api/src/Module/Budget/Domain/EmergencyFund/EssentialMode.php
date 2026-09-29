<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\EmergencyFund;

/** Which expenses count as essential: the `ESSENTIAL` analytic axis, or an explicit list of expense categories. */
enum EssentialMode: string
{
    case AXIS = 'AXIS';
    case CATEGORIES = 'CATEGORIES';
}
