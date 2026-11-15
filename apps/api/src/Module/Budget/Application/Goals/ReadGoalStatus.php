<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Accounts\Domain\InvalidCalendarMonth;
use App\Module\Budget\Domain\Goals\Goal;
use App\Module\Budget\Domain\Goals\GoalPeriodicity;
use App\Module\Budget\Domain\Goals\GoalRepository;
use App\Module\Budget\Domain\Goals\GoalValueType;
use App\Module\Budget\Domain\Goals\GoalVersion;
use App\Module\Budget\Domain\Goals\GoalVersionRepository;
use App\Module\Budget\Domain\Goals\GoalVersionResolver;
use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Foundation\Application\WorkspaceCalendar;

final readonly class ReadGoalStatus
{
    public function __construct(
        private CallerWorkspace $caller,
        private GoalRepository $goals,
        private GoalVersionRepository $versions,
        private GoalMetricReader $metrics,
        private GoalPeriodEvaluator $evaluator,
        private WorkspaceCalendar $calendar,
        private ?ResolveGoalScope $scope = null,
    ) {
    }

    /**
     * Without a month, the status is read at the current workspace-local month of the current year
     * and at December of a past year; a future year has no calculable month.
     */
    public function __invoke(string $id, ?string $monthKey = null, ?int $year = null): GoalStatusView
    {
        $today = CalendarMonth::containing($this->calendar->today());
        if (null !== $year && ($year < 1900 || $year > $today->year + 5)) {
            throw new GoalInputError('invalid_goal_year', 'A goal status year lies from 1900 to five years ahead.');
        }
        if (null === $monthKey) {
            $month = GoalCalendar::defaultMonth($today, $year ?? $today->year);
        } else {
            try {
                $month = CalendarMonth::fromString($monthKey);
            } catch (InvalidCalendarMonth) {
                throw new GoalInputError('invalid_goal_month', 'A goal status month is written YYYY-MM.');
            }
            if (null !== $year && $month->year !== $year) {
                throw new GoalInputError('invalid_goal_query', 'A goal status month belongs to its selected year.');
            }
        }
        if (GoalCalendar::isFuture($month, $today)) {
            throw new GoalInputError('future_goal_month', 'A goal status cannot be calculated for a future month.');
        }

        $workspace = $this->caller->resolve();
        $goal = $this->goals->find($workspace, $id) ?? throw new GoalNotFound('This goal does not exist in this workspace.');
        $versions = $this->versions->listByGoal($workspace, $id, 50);

        return $this->forGoal($goal, $versions, $month, $this->metrics->inputs($workspace, $month, [$goal]));
    }

    /**
     * A future month has no real figure yet: its status names that month, the definition then in
     * effect and no amount, rather than borrowing the figures of another period.
     *
     * @param list<GoalVersion> $versions
     */
    public function future(Goal $goal, array $versions, CalendarMonth $month): GoalStatusView
    {
        return $this->notCalculable($goal, GoalVersionResolver::forMonth($versions, $month), $month, 'FUTURE_MONTH', 'EMPTY');
    }

    /**
     * A month the goal does not cover, or a definition this read does not evaluate yet, has no
     * figure: the status names the month and the definition then in effect, never a borrowed value.
     */
    private function notCalculable(Goal $goal, ?GoalVersion $version, CalendarMonth $month, string $reason, string $quality): GoalStatusView
    {
        $amount = GoalValueType::AMOUNT === $version?->valueType;

        return new GoalStatusView(
            $goal->id,
            $month->key(),
            $goal->periodicity->value,
            $goal->direction()->value,
            $version?->valueType->value,
            $version?->versionNo,
            GoalPeriodicity::YEARLY === $goal->periodicity ? $version?->scheduleMode() : null,
            false,
            GoalMetricReader::policyFromVersions([]),
            $amount ? $version->targetAmount?->toString() : null,
            null,
            null,
            null,
            null,
            null,
            'NOT_CALCULABLE',
            null,
            null,
            $reason,
            $quality,
            [],
            $amount ? null : $version?->ratio?->toString(),
        );
    }

    /**
     * @param list<GoalVersion> $versions
     * @param ?GoalScopeView    $scope    the goal's resolved scope when the caller already holds it
     */
    public function forGoal(Goal $goal, array $versions, CalendarMonth $month, GoalStatusInputs $inputs, ?GoalScopeView $scope = null): GoalStatusView
    {
        $scope ??= null === $this->scope ? null : ($this->scope)($goal->workspace, $goal->scopeType, $goal->scopeRef, true);
        $archived = true === $scope?->archived;
        $categoryIds = $this->metrics->categoryBranch($goal, $inputs);
        $version = GoalVersionResolver::forMonth($versions, $month);
        if (!$goal->coversPeriod($month->key())) {
            return $this->notCalculable($goal, $version, $month, $month->key() < $goal->firstMonth() ? 'GOAL_NOT_STARTED' : 'GOAL_ENDED', 'EMPTY');
        }
        if ($goal->metric->isStock()) {
            return $this->notCalculable($goal, $version, $month, 'EVALUATION_NOT_AVAILABLE', 'EMPTY');
        }
        if (GoalPeriodicity::MONTHLY === $goal->periodicity) {
            return $this->evaluator->monthly($goal, $versions, $month, $archived, $categoryIds, $inputs);
        }
        if (GoalValueType::AMOUNT !== $version?->valueType) {
            return $this->notCalculable($goal, $version, $month, null === $version ? 'GOAL_NOT_STARTED' : 'EVALUATION_NOT_AVAILABLE', 'EMPTY');
        }

        return $this->evaluator->annual($goal, $versions, $month, $archived, $categoryIds, $inputs);
    }
}
