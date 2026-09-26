<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain\Aggregation;

/** Why a month of the requested range is not in the statistics or totals population. */
enum ExcludedReason: string
{
    case FUTURE = 'FUTURE';
    case NO_DATA = 'NO_DATA';
    case PROVISIONAL = 'PROVISIONAL';
    case NULL_VALUE = 'NULL_VALUE';
}
