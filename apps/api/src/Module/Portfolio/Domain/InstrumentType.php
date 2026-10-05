<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain;

enum InstrumentType: string
{
    case EQUITY = 'EQUITY';
    case ETF = 'ETF';
    case FUND = 'FUND';
    case BOND = 'BOND';
    case OTHER = 'OTHER';
    case EURO_FUND = 'EURO_FUND';
}
