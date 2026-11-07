<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\Goals;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Domain\WorkspaceScope;

interface GoalRepository
{
    public function lockWorkspace(WorkspaceScope $workspace): void;

    public function find(WorkspaceScope $workspace, string $id): ?Goal;

    public function findForUpdate(WorkspaceScope $workspace, string $id): ?Goal;

    /** @return list<Goal> the goals matching the filter, oldest first */
    public function search(WorkspaceScope $workspace, GoalFilter $filter, int $limit, int $offset): array;

    public function count(WorkspaceScope $workspace, GoalFilter $filter): int;

    /** The goals whose end period is null or not past, which the workspace limit bounds. */
    public function countCurrent(WorkspaceScope $workspace, CalendarMonth $today): int;

    /**
     * The goals covering any month from `$firstMonth` through `$lastMonth` (`YYYY-MM`), oldest first and bounded.
     *
     * @return list<Goal>
     */
    public function covering(WorkspaceScope $workspace, string $firstMonth, string $lastMonth, int $limit): array;

    public function add(Goal $goal): void;

    /** Removes the goal with its versions and schedule parts; false when the expected version is stale. */
    public function delete(Goal $goal, int $expectedVersion): bool;

    public function update(Goal $goal, int $expectedVersion): bool;
}
