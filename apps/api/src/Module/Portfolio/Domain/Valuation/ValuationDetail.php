<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Valuation;

enum ValuationDetail: string
{
    case TOTAL = 'TOTAL';
    case POSITIONS = 'POSITIONS';
}
