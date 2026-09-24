<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain;

use App\Module\Accounts\Domain\CalendarMonth;

/**
 * The two business days a monthly recap is answered for.
 *
 * N−1 is the last calendar day of the preceding month, never the first day of
 * the selected one: a value stamped on the 1st already carries that month's
 * first movements, so comparing against it would hide them.
 *
 * While the selected month is still running, N stops on the day the workspace
 * is living in. Reading its calendar end would publish a valuation the reader
 * has dated in the future as if it already applied.
 */
final readonly class MonthlyRecapWindow
{
    private function __construct(
        public \DateTimeImmutable $previousAsOf,
        public \DateTimeImmutable $currentAsOf,
        public bool $provisional,
    ) {
    }

    public static function of(CalendarMonth $month, \DateTimeImmutable $today): self
    {
        $firstDay = $month->firstDay();
        $lastDay = $month->lastDay();
        $day = new \DateTimeImmutable($today->format('Y-m-d'), new \DateTimeZone('UTC'));

        // A month that has not started yet holds no later valuation to leak,
        // and capping it at today would put N before N−1.
        $provisional = $day >= $firstDay && $day < $lastDay;

        return new self(
            previousAsOf: $firstDay->modify('-1 day'),
            currentAsOf: $provisional ? $day : $lastDay,
            provisional: $provisional,
        );
    }
}
