<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Mapping;

/** The instrument types the mapping may name; they match the portfolio instrument types. */
enum InstrumentKind: string
{
    case EQUITY = 'EQUITY';
    case ETF = 'ETF';
    case FUND = 'FUND';
    case BOND = 'BOND';
    case OTHER = 'OTHER';
}
