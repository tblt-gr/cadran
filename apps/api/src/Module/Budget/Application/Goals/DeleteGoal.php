<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Budget\Domain\Goals\GoalRepository;
use App\Module\Budget\Domain\Goals\GoalVersionRepository;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\Application\WorkspaceCalendar;

/** Removes a goal with its versions and schedule parts, unless a closed month would lose its evaluation. */
final readonly class DeleteGoal
{
    public function __construct(
        private CallerWorkspaceContext $caller, private GoalRepository $goals, private GoalVersionRepository $versions,
        private TransactionBoundary $transactions, private RecordAuditEvent $audit, private FindClosedGoalMonths $closed,
        private WorkspaceCalendar $calendar,
    ) {
    }

    public function __invoke(string $goalId, int $expectedVersion): void
    {
        $context = $this->caller->resolveContext();
        if (!$context->isOwner) {
            throw new WorkspaceAccessDenied('Only the workspace owner manages goals.');
        }
        $today = CalendarMonth::containing($this->calendar->today())->key();

        $this->transactions->transactional(function () use ($context, $goalId, $expectedVersion, $today): void {
            $goal = $this->goals->findForUpdate($context->workspace, $goalId) ?? throw new GoalNotFound('This goal does not exist in this workspace.');
            if ($goal->version !== $expectedVersion) {
                throw new GoalConflict('stale_goal_version', 'This goal changed; reload and try again.');
            }
            if ($this->closed->anyBetween($context->workspace, $goal->firstMonth(), min($goal->lastMonth() ?? $today, $today))) {
                throw new GoalConflict('goal_covers_closed_period', 'A goal covering a closed month cannot be deleted; stop it instead.');
            }
            $versions = $this->versions->listByGoal($context->workspace, $goal->id, 51);
            $parts = array_sum(array_map(static fn ($version): int => count($version->schedule->parts ?? []), $versions));
            if (!$this->goals->delete($goal, $goal->version)) {
                throw new GoalConflict('stale_goal_version', 'This goal changed; reload and try again.');
            }
            ($this->audit)(new AuditEventRecord($context->workspace, $context->actorId, GoalAuditEvents::DELETED, GoalAuditEvents::ENTITY, $goal->id, AuditDiff::change(
                ['version' => $goal->version],
                ['versionCount' => (string) count($versions), 'schedulePartCount' => (string) $parts],
            )));
        });
    }
}
