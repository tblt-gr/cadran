<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

enum ValuationBasis: string
{
    case SNAPSHOT = 'SNAPSHOT';
    case SNAPSHOT_PLUS_MOVEMENTS = 'SNAPSHOT_PLUS_MOVEMENTS';
    case PORTFOLIO_VALUATION = 'PORTFOLIO_VALUATION';
}
