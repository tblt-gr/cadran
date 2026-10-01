<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain;

enum AssetClass: string
{
    case EQUITY = 'EQUITY';
    case BOND = 'BOND';
    case MONEY_MARKET = 'MONEY_MARKET';
    case REAL_ESTATE = 'REAL_ESTATE';
    case COMMODITY = 'COMMODITY';
    case MIXED = 'MIXED';
    case OTHER = 'OTHER';
}
