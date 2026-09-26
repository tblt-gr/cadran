<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\Application\Double;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Accounts\Domain\PeriodClosure;
use App\Module\Accounts\Domain\PeriodClosureRepository;
use App\Module\Foundation\Domain\WorkspaceScope;

final class InMemoryPeriodClosureRepository implements PeriodClosureRepository
{
    /** @var list<PeriodClosure> */
    private array $closures = [];

    public function lockShared(WorkspaceScope $workspace): void
    {
    }

    public function lockExclusive(WorkspaceScope $workspace): void
    {
    }

    public function closedAmong(WorkspaceScope $workspace, array $months): array
    {
        return [];
    }

    public function hasActive(WorkspaceScope $workspace): bool
    {
        return [] !== $this->closures;
    }

    public function activeBetween(WorkspaceScope $workspace, CalendarMonth $from, ?CalendarMonth $to): array
    {
        return [];
    }

    public function findActive(WorkspaceScope $workspace, CalendarMonth $month): ?PeriodClosure
    {
        foreach ($this->closures as $closure) {
            if ($closure->workspace->id === $workspace->id && $closure->month->equals($month) && $closure->isActive()) {
                return $closure;
            }
        }

        return null;
    }

    public function listForYear(WorkspaceScope $workspace, int $year): array
    {
        return [];
    }

    public function add(PeriodClosure $closure): void
    {
        $this->closures[] = $closure;
    }

    public function update(PeriodClosure $closure, int $expectedVersion): bool
    {
        return true;
    }
}
