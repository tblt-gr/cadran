<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Valuation;

enum ValuationFlag: string
{
    case STALE_PRICES = 'STALE_PRICES';
    case CASH_DIFFERENCE = 'CASH_DIFFERENCE';
    case OBSERVED_TOTAL_DIFFERENCE = 'OBSERVED_TOTAL_DIFFERENCE';
    case POSITIONS_CHANGED = 'POSITIONS_CHANGED';
    case NEGATIVE_SECURITIES_VALUE = 'NEGATIVE_SECURITIES_VALUE';
}
