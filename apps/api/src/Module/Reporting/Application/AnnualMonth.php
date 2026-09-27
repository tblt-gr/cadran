<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Reporting\Domain\Aggregation\MonthState;

/** One month of an annual report: its state and, when it has data, the figures of a live read or of its snapshot. */
final readonly class AnnualMonth
{
    public function __construct(
        public CalendarMonth $month,
        public MonthState $state,
        public bool $closed,
        public bool $snapshot,
        public ?MonthFigures $figures,
    ) {
    }
}
