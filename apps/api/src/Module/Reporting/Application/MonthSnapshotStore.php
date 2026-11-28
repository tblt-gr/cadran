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

    /**
     * The same figures for several months in one read. A month without a current, readable snapshot of its
     * expected closure is absent from the result.
     *
     * @param array<string, string> $closureIdByMonthKey the active closure of each month, keyed by `YYYY-MM`
     *
     * @return array<string, MonthFigures> keyed by `YYYY-MM`
     */
    public function findMany(WorkspaceScope $workspace, array $closureIdByMonthKey): array;

    /** Keeps an existing snapshot of the same closure untouched. */
    public function capture(WorkspaceScope $workspace, CalendarMonth $month, string $closureId, MonthFigures $figures, \DateTimeImmutable $at): void;

    public function deleteForMonth(WorkspaceScope $workspace, CalendarMonth $month): void;
}
