<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

enum CeilingUnsettledReason: string
{
    case MISSING_VALUATION = 'MISSING_VALUATION';
    case CONTRIBUTIONS_NOT_TRACKED = 'CONTRIBUTIONS_NOT_TRACKED';
    case COMBINED_CEILING = 'COMBINED_CEILING';
}
