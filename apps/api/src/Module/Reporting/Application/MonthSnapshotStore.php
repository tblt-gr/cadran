<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Domain\WorkspaceScope;

/** Frozen figures of closed months, one per closure. A snapshot is written once and never edited. */
interface MonthSnapshotStore
{
    /** The figures captured for this closure, or null when none exists or its schema is not the current one. */
    public function find(WorkspaceScope $workspace, CalendarMonth $month, string $closureId): ?MonthFigures;

    /** Keeps an existing snapshot of the same closure untouched. */
    public function capture(WorkspaceScope $workspace, CalendarMonth $month, string $closureId, MonthFigures $figures, \DateTimeImmutable $at): void;

    public function deleteForMonth(WorkspaceScope $workspace, CalendarMonth $month): void;
}
