<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain\Recurrence;

/**
 * How an occurrence presents to a reader, as opposed to how it is stored.
 *
 * `LATE` exists only here. A passed expectation is read off the calendar every
 * time it is displayed, so listing occurrences stays a pure read: no `GET` ever
 * writes a status, and the same row reads `EXPECTED` today and `LATE` tomorrow
 * without anything having changed in the database.
 */
enum OccurrenceEffectiveStatus: string
{
    case EXPECTED = 'EXPECTED';
    case RECEIVED = 'RECEIVED';
    case LATE = 'LATE';

    /** `$today` is the current calendar day in the workspace timezone. */
    public static function of(TransactionRecurrenceOccurrence $occurrence, \DateTimeImmutable $today): self
    {
        if (OccurrenceStatus::RECEIVED === $occurrence->status) {
            return self::RECEIVED;
        }

        return $occurrence->expectedOn->format('Y-m-d') < $today->format('Y-m-d') ? self::LATE : self::EXPECTED;
    }
}
