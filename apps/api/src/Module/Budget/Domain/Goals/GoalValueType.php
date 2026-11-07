<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\Goals;

enum GoalValueType: string
{
    case AMOUNT = 'AMOUNT';
    /** A share of the period's cash income. */
    case INCOME_SHARE = 'INCOME_SHARE';
    /** A share of the average cash income of the twelve complete months before the month. */
    case ROLLING_INCOME_SHARE = 'ROLLING_INCOME_SHARE';
    /** The target rate itself, for the rate metrics. */
    case RATE = 'RATE';
}
