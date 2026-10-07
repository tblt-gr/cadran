<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\LifeInsurance;

enum InterestBasis: string
{
    case NET_OF_MANAGEMENT_FEES = 'NET_OF_MANAGEMENT_FEES';
    case GROSS_OF_MANAGEMENT_FEES = 'GROSS_OF_MANAGEMENT_FEES';
    case UNKNOWN = 'UNKNOWN';
}
