<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain\Recurrence;

/**
 * The two states an occurrence is ever stored in. `LATE` is deliberately
 * absent: a passed expected date is read from the calendar at presentation
 * time, so listing an occurrence never writes one.
 */
enum OccurrenceStatus: string
{
    case EXPECTED = 'EXPECTED';
    case RECEIVED = 'RECEIVED';
}
