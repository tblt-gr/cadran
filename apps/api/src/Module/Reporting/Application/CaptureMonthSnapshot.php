<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Accounts\Domain\PeriodClosureRepository;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * Freezes the figures of a month that has just been closed. It writes only for the closure that is
 * still active, so a closing undone in the meantime leaves nothing behind.
 */
final readonly class CaptureMonthSnapshot
{
    public function __construct(
        private MonthlyProjector $projector,
        private PeriodClosureRepository $closures,
        private MonthSnapshotStore $snapshots,
        private WorkspaceCalendar $calendar,
    ) {
    }

    public function __invoke(WorkspaceScope $workspace, CalendarMonth $month, string $closureId): void
    {
        if ($this->closures->findActive($workspace, $month)?->id !== $closureId) {
            return;
        }
        $figures = MonthFigures::fromProjection(($this->projector)($workspace, $month, $this->calendar->today()));
        $this->snapshots->capture($workspace, $month, $closureId, $figures, $this->calendar->now());
    }
}
