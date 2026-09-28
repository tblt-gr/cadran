<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Budget\Domain\Goals\GoalRepository;
use App\Module\Budget\Domain\Goals\GoalVersionRepository;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\Application\WorkspaceCalendar;

final readonly class CloseGoal
{
    public function __construct(private CallerWorkspaceContext $caller, private GoalRepository $goals, private GoalVersionRepository $versions, private WorkspaceCalendar $calendar, private TransactionBoundary $transactions, private RecordAuditEvent $audit, private PresentGoal $present)
    {
    }

    public function __invoke(string $id, int $expectedVersion): GoalDetailView
    {
        $context = $this->caller->resolveContext();
        if (!$context->isOwner) {
            throw new WorkspaceAccessDenied('Only the workspace owner manages goals.');
        }
        [$goal, $versions] = $this->transactions->transactional(function () use ($context, $id, $expectedVersion): array {
            $current = $this->goals->findForUpdate($context->workspace, $id);
            if (null === $current) {
                throw new GoalNotFound('This goal does not exist in this workspace.');
            }
            if ($current->version !== $expectedVersion) {
                throw new GoalConflict('stale_goal_version', 'This goal changed; reload and try again.');
            }
            try {
                $closed = $current->close($this->calendar->now());
            } catch (\DomainException $exception) {
                throw new GoalConflict('goal_closed', $exception->getMessage());
            }
            if (!$this->goals->update($closed, $current->version)) {
                throw new GoalConflict('stale_goal_version', 'This goal changed; reload and try again.');
            }
            ($this->audit)(new AuditEventRecord($context->workspace, $context->actorId, GoalAuditEvents::CLOSED, GoalAuditEvents::ENTITY, $closed->id, AuditDiff::change(['state' => 'ACTIVE', 'version' => $current->version], ['state' => 'CLOSED', 'version' => $closed->version])));

            return [$closed, $this->versions->listByGoal($context->workspace, $id, 50)];
        });

        return $this->present->detail($goal, $versions);
    }
}
