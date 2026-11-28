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

    public int $reads = 0;

    public int $batchReads = 0;

    public int $writes = 0;

    public function find(WorkspaceScope $workspace, CalendarMonth $month, string $closureId): ?MonthFigures
    {
        ++$this->reads;

        return $this->snapshots[$workspace->id.'|'.$month->key().'|'.$closureId] ?? null;
    }

    public function findMany(WorkspaceScope $workspace, array $closureIdByMonthKey): array
    {
        if ([] === $closureIdByMonthKey) {
            return [];
        }
        ++$this->batchReads;
        $found = [];
        foreach ($closureIdByMonthKey as $month => $closureId) {
            $figures = $this->snapshots[$workspace->id.'|'.$month.'|'.$closureId] ?? null;
            if (null !== $figures) {
                $found[$month] = $figures;
            }
        }

        return $found;
    }

    public function capture(WorkspaceScope $workspace, CalendarMonth $month, string $closureId, MonthFigures $figures, \DateTimeImmutable $at): void
    {
        ++$this->writes;
        $this->snapshots[$workspace->id.'|'.$month->key().'|'.$closureId] ??= $figures;
    }

    public function deleteForMonth(WorkspaceScope $workspace, CalendarMonth $month): void
    {
        ++$this->writes;
        foreach (array_keys($this->snapshots) as $key) {
            if (str_starts_with($key, $workspace->id.'|'.$month->key().'|')) {
                unset($this->snapshots[$key]);
            }
        }
    }
}
