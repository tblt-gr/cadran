<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain\Aggregation;

/** Whether a month may enter an aggregate, decided by the workspace-local calendar and first data month. */
enum MonthState: string
{
    case COMPLETE = 'COMPLETE';
    case PROVISIONAL = 'PROVISIONAL';
    case FUTURE = 'FUTURE';
    case NO_DATA = 'NO_DATA';
}
