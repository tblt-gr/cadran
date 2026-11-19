<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Foundation\Domain\DecimalValue;

/** An exact figure attached to one calendar day: a day-end balance, or the net movement of that day. */
final readonly class DayBalance
{
    public function __construct(
        public \DateTimeImmutable $day,
        public DecimalValue $amount,
    ) {
    }
}
