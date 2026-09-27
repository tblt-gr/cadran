<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Domain\WorkspaceScope;

/** Dispatched once the closing of a month has committed; the closure id ties readers to that closing. */
final readonly class PeriodClosedEvent
{
    public function __construct(
        public WorkspaceScope $workspace,
        public CalendarMonth $month,
        public string $closureId,
    ) {
    }
}
