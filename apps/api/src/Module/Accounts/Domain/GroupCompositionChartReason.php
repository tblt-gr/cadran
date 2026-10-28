<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

/**
 * Why a pie of one group would mislead. The table stays available in every case.
 */
enum GroupCompositionChartReason: string
{
    case MISSING_VALUATION = 'MISSING_VALUATION';
    case MIXED_ASSETS = 'MIXED_ASSETS';
    case ZERO_GROUP_TOTAL = 'ZERO_GROUP_TOTAL';
    case NEGATIVE_GROUP_TOTAL = 'NEGATIVE_GROUP_TOTAL';
    case NEGATIVE_COMPONENT = 'NEGATIVE_COMPONENT';
}
