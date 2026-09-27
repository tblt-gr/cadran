<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Accounts\Domain\InvalidCalendarMonth;
use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Foundation\Application\WorkspaceCalendar;

final readonly class ReadMonthlyProjection
{
    public function __construct(
        private CallerWorkspace $caller,
        private MonthlyProjector $compute,
        private WorkspaceCalendar $calendar,
    ) {
    }

    public function __invoke(string $requestedMonth): MonthlyProjectionView
    {
        try {
            $month = CalendarMonth::fromString($requestedMonth);
        } catch (InvalidCalendarMonth $exception) {
            throw new InvalidMonthlyProjectionQuery($exception->getMessage(), previous: $exception);
        }

        return ($this->compute)($this->caller->resolve(), $month, $this->calendar->today());
    }
}
