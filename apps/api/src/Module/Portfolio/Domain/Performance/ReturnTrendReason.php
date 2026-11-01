<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Performance;

/** Why the cumulative return of the trailing twelve months is not a figure. */
enum ReturnTrendReason: string
{
    /** The chain starts after the window: nothing to chain yet. */
    case NO_HISTORY = 'NO_HISTORY';

    /** A month of the chain has no monthly rate, typically no month-end valuation. */
    case MISSING_MONTH = 'MISSING_MONTH';

    /** A growth factor at or below zero: the chain is not defined past a total loss. */
    case NON_POSITIVE_GROWTH_FACTOR = 'NON_POSITIVE_GROWTH_FACTOR';
}
