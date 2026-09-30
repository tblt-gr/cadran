<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Budget\Domain\Goals\GoalKind;
use App\Module\Budget\Domain\Goals\GoalRepository;
use App\Module\Budget\Domain\Goals\GoalState;
use App\Module\Budget\Domain\Goals\GoalVersionRepository;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\Application\WorkspaceCalendar;

final readonly class PreviewGoalCorrection
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private GoalRepository $goals,
        private GoalVersionRepository $versions,
        private BuildGoalRevision $revision,
        private ReadGoalStatus $statuses,
        private WorkspaceCalendar $calendar,
    ) {
    }

    public function __invoke(UpdateGoalInput $input): GoalCorrectionPreviewView
    {
        $context = $this->caller->resolveContext();
        if (!$context->isOwner) {
            throw new WorkspaceAccessDenied('Only the workspace owner manages goals.');
        }
        $goal = $this->goals->find($context->workspace, $input->goalId) ?? throw new GoalNotFound('This goal does not exist in this workspace.');
        if ($goal->version !== $input->expectedVersion) {
            throw new GoalConflict('stale_goal_version', 'This goal changed; reload and try again.');
        }
        if (GoalState::CLOSED === $goal->state) {
            throw new GoalConflict('goal_closed', 'A closed goal cannot be edited.');
        }
        $versions = $this->versions->listByGoal($context->workspace, $goal->id, 51);
        $revision = ($this->revision)($goal, $versions, $input, $context->actorId);
        if (null === $revision->definition) {
            return new GoalCorrectionPreviewView([]);
        }
        if (count($versions) >= 50) {
            throw new GoalConflict('goal_version_limit', 'A goal may have at most 50 versions.');
        }
        $afterVersions = [$revision->definition, ...$versions];
        $current = CalendarMonth::containing($this->calendar->today());
        $cursor = CalendarMonth::fromString($revision->definition->effectiveFrom);
        $lastMonth = $current->key();
        if (GoalKind::ANNUAL === $goal->kind) {
            $year = $revision->definition->year ?? throw new \LogicException('An annual goal definition carries a year.');
            $lastMonth = min($lastMonth, sprintf('%04d-12', $year));
        }
        $months = [];
        while ($cursor->key() <= $lastMonth) {
            $inputs = $this->statuses->inputs($context->workspace, $cursor, [$goal]);
            $before = $this->statuses->forGoal($goal, $versions, $cursor, $inputs);
            $after = $this->statuses->forGoal($goal, $afterVersions, $cursor, $inputs);
            $months[] = new GoalCorrectionPreviewMonthView($cursor->key(), self::state($goal->kind, $before), self::state($goal->kind, $after));
            $cursor = CalendarMonth::containing($cursor->firstDay()->modify('+1 month'));
        }

        return new GoalCorrectionPreviewView($months);
    }

    private static function state(GoalKind $kind, GoalStatusView $status): GoalCorrectionPreviewStateView
    {
        return new GoalCorrectionPreviewStateView($status->goalVersion, GoalKind::ANNUAL === $kind ? $status->expected : $status->target, $status->status);
    }
}
