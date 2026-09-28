<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Budget\Domain\Goals\GoalRepository;
use App\Module\Budget\Domain\Goals\GoalVersionRepository;
use App\Module\Foundation\Application\CallerWorkspace;

final readonly class ReadGoal
{
    public function __construct(private CallerWorkspace $caller, private GoalRepository $goals, private GoalVersionRepository $versions, private PresentGoal $present)
    {
    }

    public function __invoke(string $id): GoalDetailView
    {
        $workspace = $this->caller->resolve();
        $goal = $this->goals->find($workspace, $id) ?? throw new GoalNotFound('This goal does not exist in this workspace.');

        return $this->present->detail($goal, $this->versions->listByGoal($workspace, $id, 50));
    }
}
