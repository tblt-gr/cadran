<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Budget\Domain\Goals\GoalRepository;
use App\Module\Budget\Domain\Goals\GoalState;
use App\Module\Budget\Domain\Goals\GoalVersionRepository;
use App\Module\Budget\Domain\Goals\GoalVersionResolver;
use App\Module\Budget\Domain\Goals\InvalidGoal;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Application\WorkspaceAccessDenied;

final readonly class UpdateGoal
{
    public function __construct(
        private CallerWorkspaceContext $caller, private GoalRepository $goals, private GoalVersionRepository $versions,
        private TransactionBoundary $transactions, private RecordAuditEvent $audit, private PresentGoal $present,
        private BuildGoalRevision $revision,
    ) {
    }

    public function __invoke(UpdateGoalInput $input): GoalDetailView
    {
        $context = $this->caller->resolveContext();
        if (!$context->isOwner) {
            throw new WorkspaceAccessDenied('Only the workspace owner manages goals.');
        }
        if ($input->identitySubmitted) {
            throw new GoalInputError('goal_identity_immutable', 'A goal metric and scope cannot change.');
        }

        [$goal, $allVersions] = $this->transactions->transactional(function () use ($context, $input): array {
            $current = $this->goals->findForUpdate($context->workspace, $input->goalId);
            if (null === $current) {
                throw new GoalNotFound('This goal does not exist in this workspace.');
            }
            if ($current->version !== $input->expectedVersion) {
                throw new GoalConflict('stale_goal_version', 'This goal changed; reload and try again.');
            }
            if (GoalState::CLOSED === $current->state) {
                throw new GoalConflict('goal_closed', 'A closed goal cannot be edited.');
            }
            $existing = $this->versions->listByGoal($context->workspace, $current->id, 51);
            $revision = ($this->revision)($current, $existing, $input, $context->actorId);
            $definition = $revision->definition;
            $label = trim($input->label);
            if (null === $definition && $label === $current->label) {
                return [$current, $existing];
            }
            if (null !== $definition && count($existing) >= 50) {
                throw new GoalConflict('goal_version_limit', 'A goal may have at most 50 versions.');
            }
            try {
                $updated = $label === $current->label ? $current->reviseDefinition() : $current->reviseLabel($label);
            } catch (InvalidGoal $exception) {
                throw new GoalInputError('invalid_goal_target', $exception->getMessage());
            }
            if (!$this->goals->update($updated, $current->version)) {
                throw new GoalConflict('stale_goal_version', 'This goal changed; reload and try again.');
            }
            $previousDefinition = null;
            if (null !== $definition) {
                $previousDefinition = GoalVersionResolver::forMonth($existing, CalendarMonth::fromString($definition->effectiveFrom));
                $this->versions->add($definition);
                array_unshift($existing, $definition);
            }
            $changed = array_values(array_filter([$label === $current->label ? null : 'label', ...$revision->changedFields]));
            $event = true === $definition?->retroactive ? GoalAuditEvents::CORRECTED : GoalAuditEvents::UPDATED;
            ($this->audit)(new AuditEventRecord($context->workspace, $context->actorId, $event, GoalAuditEvents::ENTITY, $updated->id, AuditDiff::change(
                ['version' => $current->version, 'definitionVersion' => $previousDefinition?->versionNo],
                array_filter(['version' => $updated->version, 'definitionVersion' => $definition?->versionNo, 'effectiveFrom' => $definition?->effectiveFrom, 'changedFields' => implode(',', $changed)], static fn (mixed $value): bool => null !== $value),
            )));

            return [$updated, $existing];
        });

        return $this->present->detail($goal, $allVersions);
    }
}
