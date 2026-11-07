<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\Goals;

use App\Module\Foundation\Domain\WorkspaceScope;

interface GoalVersionRepository
{
    /** @return list<GoalVersion> newest version number first */
    public function listByGoal(WorkspaceScope $workspace, string $goalId, int $limit): array;

    /**
     * The versions of several goals in one read, newest version number first within each goal.
     *
     * @param list<string> $goalIds
     *
     * @return array<string, list<GoalVersion>>
     */
    public function listByGoals(WorkspaceScope $workspace, array $goalIds): array;

    /** Removes the versions taking effect after a month (`YYYY-MM`), with their schedule parts; returns how many. */
    public function deleteEffectiveAfter(WorkspaceScope $workspace, string $goalId, string $month): int;

    public function add(GoalVersion $version): void;
}
