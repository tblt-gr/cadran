<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain\Aggregation;

/** Whether the running month counts in averages, medians and extremes. */
enum IncompleteMonths: string
{
    case EXCLUDE = 'exclude';
    case INCLUDE = 'include';
}
