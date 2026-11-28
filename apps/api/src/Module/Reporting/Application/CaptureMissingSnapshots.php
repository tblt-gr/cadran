<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Accounts\Domain\PeriodClosureRepository;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * Captures the snapshot of every closed month that has none, or only one written before account
 * values were kept (the criterion of {@see LoadAnnualMonths}). Reads never write, so after a
 * historical import or a snapshot schema change such months are computed live until this runs. A
 * month already up to date is not counted as skipped: skipped means a month reopened between the
 * listing and its capture.
 *
 * Each month runs in its own transaction under the shared closure lock, so a reopening waits for at
 * most one month and a month reopened meanwhile is left alone.
 */
final readonly class CaptureMissingSnapshots
{
    public function __construct(
        private PeriodClosureRepository $closures,
        private MonthSnapshotStore $snapshots,
        private CaptureMonthSnapshot $capture,
        private TransactionBoundary $boundary,
        private WorkspaceCalendar $calendar,
    ) {
    }

    public function __invoke(WorkspaceScope $workspace): CaptureMissingSnapshotsResult
    {
        $months = $this->closures->activeBetween(
            $workspace,
            new CalendarMonth(1970, 1),
            CalendarMonth::containing($this->calendar->today()),
        );
        $captured = 0;
        $skipped = 0;
        foreach ($months as $month) {
            $outcome = $this->captureOne($workspace, $month);
            if (true === $outcome) {
                ++$captured;
            } elseif (false === $outcome) {
                ++$skipped;
            }
        }

        return new CaptureMissingSnapshotsResult($captured, $skipped);
    }

    /** True when captured, false when the month was reopened meanwhile, null when already up to date. */
    private function captureOne(WorkspaceScope $workspace, CalendarMonth $month): ?bool
    {
        return $this->boundary->transactional(function () use ($workspace, $month): ?bool {
            $this->closures->lockShared($workspace);
            $closure = $this->closures->findActive($workspace, $month);
            if (null === $closure) {
                return false;
            }
            $figures = $this->snapshots->find($workspace, $month, $closure->id);
            if (null !== $figures && null !== $figures->accountWeights) {
                return null;
            }
            if (null !== $figures) {
                $this->snapshots->deleteForMonth($workspace, $month);
            }
            ($this->capture)($workspace, $month, $closure->id);

            return true;
        });
    }
}
