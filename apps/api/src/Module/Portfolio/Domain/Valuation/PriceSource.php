<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Valuation;

enum PriceSource: string
{
    case MANUAL = 'MANUAL';
    case IMPORT = 'IMPORT';
}
