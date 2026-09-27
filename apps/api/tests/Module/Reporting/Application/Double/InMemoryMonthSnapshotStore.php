<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\Application\Double;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reporting\Application\MonthFigures;
use App\Module\Reporting\Application\MonthSnapshotStore;

final class InMemoryMonthSnapshotStore implements MonthSnapshotStore
{
    /** @var array<string, MonthFigures> */
    public array $snapshots = [];

    public function find(WorkspaceScope $workspace, CalendarMonth $month, string $closureId): ?MonthFigures
    {
        return $this->snapshots[$workspace->id.'|'.$month->key().'|'.$closureId] ?? null;
    }

    public function capture(WorkspaceScope $workspace, CalendarMonth $month, string $closureId, MonthFigures $figures, \DateTimeImmutable $at): void
    {
        $this->snapshots[$workspace->id.'|'.$month->key().'|'.$closureId] ??= $figures;
    }

    public function deleteForMonth(WorkspaceScope $workspace, CalendarMonth $month): void
    {
        foreach (array_keys($this->snapshots) as $key) {
            if (str_starts_with($key, $workspace->id.'|'.$month->key().'|')) {
                unset($this->snapshots[$key]);
            }
        }
    }
}
