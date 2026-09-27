<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Domain\WorkspaceScope;

/** Dispatched once the reopening of a month has committed. */
final readonly class PeriodReopenedEvent
{
    public function __construct(
        public WorkspaceScope $workspace,
        public CalendarMonth $month,
        public string $closureId,
    ) {
    }
}
