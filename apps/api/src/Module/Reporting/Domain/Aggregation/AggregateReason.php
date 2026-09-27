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

    /** A multi-year change or summary average whose year is not entirely `COMPLETE`. */
    case PARTIAL_YEAR = 'PARTIAL_YEAR';

    /** A year-over-year change ratio whose previous-year base is exactly zero. */
    case ZERO_BASE = 'ZERO_BASE';

    /** A year-over-year change ratio whose previous-year base is negative. */
    case NEGATIVE_BASE = 'NEGATIVE_BASE';

    /** A multi-year account column whose whole year lies before the account's opening date. */
    case ACCOUNT_NOT_OPEN = 'ACCOUNT_NOT_OPEN';
}
