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

    public int $rangeReads = 0;

    public int $monthReads = 0;

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
        $months = [];
        foreach ($this->closures as $closure) {
            $key = $closure->month->key();
            if ($closure->workspace->id === $workspace->id && $closure->isActive() && $key >= $from->key() && (null === $to || $key <= $to->key())) {
                $months[$key] = $closure->month;
            }
        }
        ksort($months);

        return array_values($months);
    }

    public function activeClosuresBetween(WorkspaceScope $workspace, CalendarMonth $from, CalendarMonth $to): array
    {
        ++$this->rangeReads;
        $found = [];
        foreach ($this->closures as $closure) {
            $key = $closure->month->key();
            if ($closure->workspace->id === $workspace->id && $closure->isActive() && $key >= $from->key() && $key <= $to->key()) {
                $found[$key] = $closure;
            }
        }
        ksort($found);

        return array_values($found);
    }

    public function findActive(WorkspaceScope $workspace, CalendarMonth $month): ?PeriodClosure
    {
        ++$this->monthReads;
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

    public function reopenAll(): void
    {
        foreach ($this->closures as $index => $closure) {
            $this->closures[$index] = $closure->reopen('Corrections', new \DateTimeImmutable('2026-09-02T10:00:00+00:00'));
        }
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
