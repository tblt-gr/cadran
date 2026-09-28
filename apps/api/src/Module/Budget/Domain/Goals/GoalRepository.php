<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\Goals;

use App\Module\Foundation\Domain\WorkspaceScope;

interface GoalRepository
{
    public function lockWorkspace(WorkspaceScope $workspace): void;

    public function find(WorkspaceScope $workspace, string $id): ?Goal;

    public function findForUpdate(WorkspaceScope $workspace, string $id): ?Goal;

    /** @return list<Goal> */
    public function listForYear(WorkspaceScope $workspace, int $year, GoalState $state, int $limit): array;

    public function countActive(WorkspaceScope $workspace): int;

    public function add(Goal $goal): void;

    public function update(Goal $goal, int $expectedVersion): bool;
}
