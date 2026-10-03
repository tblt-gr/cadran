<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Valuation;

enum CashSource: string
{
    case DERIVED = 'DERIVED';
    case OBSERVED = 'OBSERVED';
}
