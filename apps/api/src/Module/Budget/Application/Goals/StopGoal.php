<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Budget\Domain\Goals\GoalRepository;
use App\Module\Budget\Domain\Goals\GoalVersionRepository;
use App\Module\Budget\Domain\Goals\InvalidGoal;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\Application\WorkspaceCalendar;

/** Ends a goal after a period so that it no longer applies to later ones; the past keeps its evaluation. */
final readonly class StopGoal
{
    public function __construct(
        private CallerWorkspaceContext $caller, private GoalRepository $goals, private GoalVersionRepository $versions,
        private TransactionBoundary $transactions, private RecordAuditEvent $audit, private PresentGoal $present,
        private FindClosedGoalMonths $closed, private WorkspaceCalendar $calendar,
    ) {
    }

    public function __invoke(StopGoalInput $input): GoalDetailView
    {
        $context = $this->caller->resolveContext();
        if (!$context->isOwner) {
            throw new WorkspaceAccessDenied('Only the workspace owner manages goals.');
        }
        $today = CalendarMonth::containing($this->calendar->today())->key();

        [$goal, $versions] = $this->transactions->transactional(function () use ($context, $input, $today): array {
            $current = $this->goals->findForUpdate($context->workspace, $input->goalId) ?? throw new GoalNotFound('This goal does not exist in this workspace.');
            if ($current->version !== $input->expectedVersion) {
                throw new GoalConflict('stale_goal_version', 'This goal changed; reload and try again.');
            }
            try {
                $stopped = $current->stoppedAt($input->lastPeriod);
            } catch (InvalidGoal $exception) {
                throw new GoalInputError('invalid_goal_period', $exception->getMessage());
            }
            // Every month the goal used to cover after the new last period keeps its evaluation only if it is still open.
            $stoppedMonth = $stopped->lastMonth() ?? throw new \LogicException('A stopped goal has an end.');
            $next = CalendarMonth::fromString($stoppedMonth)->firstDay()->modify('+1 month');
            $covered = $current->lastMonth() ?? $today;
            if ($this->closed->anyBetween($context->workspace, $next->format('Y-m'), min($covered, $today))) {
                throw new GoalConflict('goal_closed_period', 'A goal cannot stop before a closed month it covers.');
            }
            if (!$this->goals->update($stopped, $current->version)) {
                throw new GoalConflict('stale_goal_version', 'This goal changed; reload and try again.');
            }
            // A revision planned after the new end would otherwise stay in the history past the goal's end.
            $removed = $this->versions->deleteEffectiveAfter($context->workspace, $stopped->id, $stoppedMonth);
            ($this->audit)(new AuditEventRecord($context->workspace, $context->actorId, GoalAuditEvents::STOPPED, GoalAuditEvents::ENTITY, $stopped->id, AuditDiff::change(
                ['version' => $current->version, 'endPeriod' => $current->endPeriod],
                ['version' => $stopped->version, 'endPeriod' => $stopped->endPeriod, 'removedVersionCount' => (string) $removed],
            )));

            return [$stopped, $this->versions->listByGoal($context->workspace, $stopped->id, 50)];
        });

        return $this->present->detail($goal, $versions);
    }
}
