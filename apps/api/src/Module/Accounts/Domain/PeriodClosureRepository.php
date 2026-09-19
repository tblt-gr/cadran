<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Foundation\Domain\WorkspaceScope;

interface PeriodClosureRepository
{
    /**
     * Held until the surrounding transaction ends. Writers share it, so they
     * never wait on each other; a closing or reopening takes it exclusively
     * and therefore waits for every in-flight write to commit, and every later
     * write sees the closure it left.
     */
    public function lockShared(WorkspaceScope $workspace): void;

    public function lockExclusive(WorkspaceScope $workspace): void;

    /**
     * Which of these months are closed right now.
     *
     * @param list<CalendarMonth> $months
     *
     * @return list<CalendarMonth>
     */
    public function closedAmong(WorkspaceScope $workspace, array $months): array;

    public function hasActive(WorkspaceScope $workspace): bool;

    public function findActive(WorkspaceScope $workspace, CalendarMonth $month): ?PeriodClosure;

    /**
     * Every closure, active or reopened, of one year, newest month first.
     *
     * @return list<PeriodClosure>
     */
    public function listForYear(WorkspaceScope $workspace, int $year): array;

    public function add(PeriodClosure $closure): void;

    /** Returns false when the expected version is stale. */
    public function update(PeriodClosure $closure, int $expectedVersion): bool;
}
