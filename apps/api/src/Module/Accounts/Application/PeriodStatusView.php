<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Accounts\Domain\PeriodClosure;

/** The state of one month: its active closure, or what would block closing it. */
final readonly class PeriodStatusView
{
    /** @param list<PeriodClosingBlocker> $blockers empty when closed or when nothing blocks */
    public function __construct(
        public CalendarMonth $month,
        public ?PeriodClosure $closure,
        public array $blockers,
        public bool $ended,
    ) {
    }
}
