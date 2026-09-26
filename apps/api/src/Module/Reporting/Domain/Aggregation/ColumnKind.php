<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain\Aggregation;

/** How a report column behaves over time. */
enum ColumnKind: string
{
    /** Additive over time: income, expenses, transfers, net-worth delta. */
    case FLOW = 'FLOW';
    /** A level at month end: net worth, account value, group value. */
    case STOCK = 'STOCK';
    /** A ratio of two flow columns of the same asset. */
    case RATE = 'RATE';
}
