<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain\Aggregation;

use App\Module\Accounts\Domain\CalendarMonth;

final readonly class ExcludedMonth
{
    public function __construct(public CalendarMonth $month, public ExcludedReason $reason)
    {
    }
}
