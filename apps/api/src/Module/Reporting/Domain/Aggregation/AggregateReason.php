<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain\Aggregation;

/** Why one aggregate figure could not be produced. */
enum AggregateReason: string
{
    case MISSING_MONTH_VALUE = 'MISSING_MONTH_VALUE';
    case MIXED_METRIC_POLICIES = 'MIXED_METRIC_POLICIES';
    case MIXED_ASSETS = 'MIXED_ASSETS';
    case ZERO_DENOMINATOR = 'ZERO_DENOMINATOR';
    case NEGATIVE_DENOMINATOR = 'NEGATIVE_DENOMINATOR';
    case NOT_ADDITIVE = 'NOT_ADDITIVE';
    case EMPTY_POPULATION = 'EMPTY_POPULATION';
    case POLICY_MISMATCH = 'POLICY_MISMATCH';
}
