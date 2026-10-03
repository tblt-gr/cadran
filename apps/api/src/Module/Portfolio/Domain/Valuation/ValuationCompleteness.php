<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Valuation;

enum ValuationCompleteness: string
{
    case COMPLETE = 'COMPLETE';
    case PARTIAL = 'PARTIAL';
}
