<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

enum NetWorthShareReason: string
{
    case MISSING_VALUATION = 'MISSING_VALUATION';
    case ZERO_ELIGIBLE_NET_WORTH = 'ZERO_ELIGIBLE_NET_WORTH';
    case NEGATIVE_ELIGIBLE_NET_WORTH = 'NEGATIVE_ELIGIBLE_NET_WORTH';
}
