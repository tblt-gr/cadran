<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain\Aggregation;

use App\Module\Accounts\Domain\CalendarMonth;

/** One extreme month of a response-only aggregate, whose value may exceed NUMERIC(50,24). */
final readonly class ResponseExtreme
{
    public function __construct(public string $value, public CalendarMonth $month)
    {
    }
}
