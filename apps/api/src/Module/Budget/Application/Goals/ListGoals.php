<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Budget\Domain\Goals\GoalRepository;
use App\Module\Budget\Domain\Goals\GoalState;
use App\Module\Budget\Domain\Goals\GoalVersionRepository;
use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Foundation\Application\WorkspaceCalendar;

final readonly class ListGoals
{
    public function __construct(private CallerWorkspace $caller, private GoalRepository $goals, private GoalVersionRepository $versions, private PresentGoal $present, private ReadGoalStatus $readStatus, private ResolveGoalScope $scope, private WorkspaceCalendar $calendar)
    {
    }

    public const int MAX_PAGE = 1000;
    public const int MAX_PAGE_SIZE = 100;
    public const int DEFAULT_PAGE_SIZE = 50;

    public function __invoke(?int $year, string $state, ?string $monthKey = null, int $page = 1, int $pageSize = self::DEFAULT_PAGE_SIZE): GoalPage
    {
        if ($page < 1 || $page > self::MAX_PAGE || $pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE) {
            throw new GoalInputError('invalid_goal_query', 'The page or page size is outside its bounds.');
        }
        $today = CalendarMonth::containing($this->calendar->today());
        $year ??= $today->year;
        if ($year < 1900 || $year > $today->year + 5) {
            throw new GoalInputError('invalid_goal_year', 'A goal list year lies from 1900 to five years ahead.');
        }
        if (null === $monthKey) {
            $month = ReadGoalStatus::defaultMonth($today, $year);
        } else {
            try {
                $month = CalendarMonth::fromString($monthKey);
            } catch (\DomainException) {
                throw new GoalInputError('invalid_goal_month', 'A goal status month is written YYYY-MM.');
            }
            if ($month->year !== $year) {
                throw new GoalInputError('invalid_goal_query', 'A goal list month belongs to its selected year.');
            }
            if (ReadGoalStatus::isFuture($month, $today)) {
                throw new GoalInputError('future_goal_month', 'A goal status cannot be calculated for a future month.');
            }
        }
        $goalState = match ($state) {
            'active' => GoalState::ACTIVE,
            'closed' => GoalState::CLOSED,
            default => throw new GoalInputError('invalid_goal_query', 'A goal state is active or closed.'),
        };
        $workspace = $this->caller->resolve();
        // A workspace lists at most 50 goals per year and state: pages slice that bounded set.
        $all = $this->goals->listForYear($workspace, $year, $goalState, 50);
        $goals = array_slice($all, ($page - 1) * $pageSize, $pageSize);
        // Only a future year lands here on a future month: its goals have no real figure to read.
        $future = ReadGoalStatus::isFuture($month, $today);
        $inputs = $future ? null : $this->readStatus->inputs($workspace, $month, $goals);

        $items = array_map(function ($goal) use ($workspace, $year, $month, $inputs): GoalSummaryView {
            $versions = $this->versions->listByGoal($workspace, $goal->id, 50);
            $scope = ($this->scope)($workspace, $goal->scopeType, $goal->scopeRef, true);
            $status = null === $inputs
                ? $this->readStatus->future($goal, $versions, $month)
                : $this->readStatus->forGoal($goal, $versions, $month, $inputs, $scope);

            return $this->present->summary($goal, $versions, $year, $status, $scope);
        }, $goals);

        return new GoalPage($items, $page, $pageSize, count($all));
    }
}
