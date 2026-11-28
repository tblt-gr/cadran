<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Accounts\Domain\PeriodClosureRepository;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * Freezes the figures of a month that has just been closed. It writes only for the closure that is
 * still active, so a closing undone in the meantime leaves nothing behind, and never freezes figures
 * computed without their policy definition.
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

    /**
     * @param bool $replace drops the outdated snapshot of the month first, only when a new one is written
     *
     * @return bool whether a snapshot was written
     */
    public function __invoke(WorkspaceScope $workspace, CalendarMonth $month, string $closureId, bool $replace = false): bool
    {
        if ($this->closures->findActive($workspace, $month)?->id !== $closureId) {
            return false;
        }
        $projection = ($this->projector)($workspace, $month, $this->calendar->today());
        if (!$projection->metricPolicy->definitionKnown()) {
            return false;
        }
        if ($replace) {
            $this->snapshots->deleteForMonth($workspace, $month);
        }
        $this->snapshots->capture($workspace, $month, $closureId, MonthFigures::fromProjection($projection), $this->calendar->now());

        return true;
    }
}
