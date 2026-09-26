<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain\Aggregation;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Domain\DecimalValue;

final readonly class MonthExtreme
{
    public function __construct(public DecimalValue $value, public CalendarMonth $month)
    {
    }
}
