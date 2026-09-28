<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Budget\Domain\Goals\GoalRepository;
use App\Module\Budget\Domain\Goals\GoalState;
use App\Module\Budget\Domain\Goals\GoalVersionRepository;
use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Foundation\Application\WorkspaceCalendar;

final readonly class ListGoals
{
    public function __construct(private CallerWorkspace $caller, private GoalRepository $goals, private GoalVersionRepository $versions, private PresentGoal $present, private WorkspaceCalendar $calendar)
    {
    }

    /** @return list<GoalSummaryView> */
    public function __invoke(?int $year, string $state): array
    {
        $year ??= (int) $this->calendar->today()->format('Y');
        if ($year < 1900 || $year > (int) $this->calendar->today()->format('Y') + 5) {
            throw new GoalInputError('invalid_goal_year', 'A goal list year lies from 1900 to five years ahead.');
        }
        $goalState = match ($state) {
            'active' => GoalState::ACTIVE,
            'closed' => GoalState::CLOSED,
            default => throw new GoalInputError('invalid_goal_query', 'A goal state is active or closed.'),
        };
        $workspace = $this->caller->resolve();

        return array_map(fn ($goal): GoalSummaryView => $this->present->summary($goal, $this->versions->listByGoal($workspace, $goal->id, 50), $year), $this->goals->listForYear($workspace, $year, $goalState, 50));
    }
}
