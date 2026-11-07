<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Budget\Domain\Goals\GoalFilter;
use App\Module\Budget\Domain\Goals\GoalPeriodicity;
use App\Module\Budget\Domain\Goals\GoalRepository;
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

    public function __invoke(?string $periodicity = null, ?string $period = null, bool $current = true, int $page = 1, int $pageSize = self::DEFAULT_PAGE_SIZE): GoalPage
    {
        if ($page < 1 || $page > self::MAX_PAGE || $pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE) {
            throw new GoalInputError('invalid_goal_query', 'The page or page size is outside its bounds.');
        }
        $today = CalendarMonth::containing($this->calendar->today());
        $goalPeriodicity = null === $periodicity ? null : (GoalPeriodicity::tryFrom($periodicity) ?? throw new GoalInputError('invalid_goal_query', 'A goal periodicity is MONTHLY or YEARLY.'));
        $month = $today;
        if (null !== $period) {
            if (!GoalPeriodicity::YEARLY->accepts($period) && !GoalPeriodicity::MONTHLY->accepts($period)) {
                throw new GoalInputError('invalid_goal_query', 'A goal period is written YYYY-MM or YYYY.');
            }
            if ((int) substr($period, 0, 4) < 1900 || (int) substr($period, 0, 4) > $today->year + 5) {
                throw new GoalInputError('invalid_goal_year', 'A goal list period lies from 1900 to five years ahead.');
            }
            $month = 4 === strlen($period) ? ReadGoalStatus::defaultMonth($today, (int) $period) : CalendarMonth::fromString($period);
        }
        $workspace = $this->caller->resolve();
        $filter = new GoalFilter($today, $goalPeriodicity, $period, $current);
        $goals = $this->goals->search($workspace, $filter, $pageSize, ($page - 1) * $pageSize);
        $total = $this->goals->count($workspace, $filter);
        // A future month has no real figure to read: its goals show their definition without a status amount.
        $future = ReadGoalStatus::isFuture($month, $today);
        $inputs = $future ? null : $this->readStatus->inputs($workspace, $month, $goals);
        $items = array_map(function ($goal) use ($workspace, $month, $today, $inputs): GoalSummaryView {
            $versions = $this->versions->listByGoal($workspace, $goal->id, 50);
            $scope = ($this->scope)($workspace, $goal->scopeType, $goal->scopeRef, true);
            $status = null === $inputs
                ? $this->readStatus->future($goal, $versions, $month)
                : $this->readStatus->forGoal($goal, $versions, $month, $inputs, $scope);

            return $this->present->summary($goal, $versions, $today, $status, $scope);
        }, $goals);

        return new GoalPage($items, $page, $pageSize, $total);
    }
}
