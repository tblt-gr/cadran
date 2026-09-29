<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\EmergencyFund;

use App\Module\Accounts\Domain\CalendarMonth;

/** The complete months an emergency-fund average is read over; it never holds the as-of month. */
final readonly class ReferencePeriod
{
    public function __construct(
        public CalendarMonth $start,
        public CalendarMonth $end,
        public int $expectedMonths,
    ) {
    }
}
