<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Accounts\Domain\InvalidCalendarMonth;
use App\Module\Accounts\Domain\PeriodClosureRepository;
use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Foundation\Application\WorkspaceCalendar;

/** Reads a month, closed or not. Reading is never refused by a closure. */
final readonly class ReadPeriodStatus
{
    public function __construct(
        private CallerWorkspace $caller,
        private PeriodClosureRepository $closures,
        private AssessPeriodClosing $assess,
        private WorkspaceCalendar $calendar,
    ) {
    }

    public function __invoke(string $period): PeriodStatusView
    {
        try {
            $month = CalendarMonth::fromString($period);
        } catch (InvalidCalendarMonth $exception) {
            throw new InvalidPeriodClosureInput($exception->getMessage(), previous: $exception);
        }
        $workspace = $this->caller->resolve();
        $closure = $this->closures->findActive($workspace, $month);

        return new PeriodStatusView(
            $month,
            $closure,
            null === $closure ? ($this->assess)($workspace, $month) : [],
            $month->lastDay() < $this->calendar->today(),
        );
    }
}
