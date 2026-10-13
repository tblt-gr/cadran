<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\Goals;

use App\Module\Foundation\Domain\WorkspaceScope;

interface GoalVersionRepository
{
    /** @return list<GoalVersion> newest version number first */
    public function listByGoal(WorkspaceScope $workspace, string $goalId, int $limit): array;

    public function add(GoalVersion $version): void;
}
