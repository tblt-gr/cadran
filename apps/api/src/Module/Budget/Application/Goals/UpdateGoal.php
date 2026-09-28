<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Budget\Domain\Goals\GoalKind;
use App\Module\Budget\Domain\Goals\GoalRepository;
use App\Module\Budget\Domain\Goals\GoalState;
use App\Module\Budget\Domain\Goals\GoalVersion;
use App\Module\Budget\Domain\Goals\GoalVersionRepository;
use App\Module\Budget\Domain\Goals\InvalidGoal;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\UuidGenerator;

final readonly class UpdateGoal
{
    public function __construct(
        private CallerWorkspaceContext $caller, private GoalRepository $goals, private GoalVersionRepository $versions,
        private WorkspaceCalendar $calendar, private UuidGenerator $uuid, private TransactionBoundary $transactions,
        private RecordAuditEvent $audit, private PresentGoal $present,
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
            $latest = $existing[0] ?? throw new \LogicException('A goal must have a definition.');
            $first = $existing[array_key_last($existing)];
            $definition = $this->definition($current, $latest, $first, $input, count($existing) + 1, $context->actorId);
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
            if (null !== $definition) {
                $this->versions->add($definition);
                array_unshift($existing, $definition);
            }
            $changed = array_values(array_filter([$label === $current->label ? null : 'label', null === $definition ? null : (GoalKind::ANNUAL === $current->kind ? 'targetAmount' : 'ratio')]));
            ($this->audit)(new AuditEventRecord($context->workspace, $context->actorId, GoalAuditEvents::UPDATED, GoalAuditEvents::ENTITY, $updated->id, AuditDiff::change(['version' => $current->version], ['version' => $updated->version, 'changedFields' => implode(',', $changed), 'definitionVersion' => $definition?->versionNo])));

            return [$updated, $existing];
        });

        return $this->present->detail($goal, $allVersions);
    }

    private function definition(\App\Module\Budget\Domain\Goals\Goal $goal, GoalVersion $latest, GoalVersion $first, UpdateGoalInput $input, int $number, string $actorId): ?GoalVersion
    {
        $now = $this->calendar->now();
        $today = $this->calendar->today();
        $currentMonth = CalendarMonth::containing($today)->key();
        // Never earlier than the current month, and never earlier than the goal's own
        // first version, so an edit cannot silently apply before the goal itself can.
        $effectiveMonth = $currentMonth > $first->effectiveFrom ? $currentMonth : $first->effectiveFrom;
        try {
            if (GoalKind::ANNUAL === $goal->kind) {
                if (null !== $input->ratio) {
                    throw new GoalInputError('invalid_goal_target', 'An annual goal cannot take a ratio.');
                }
                if (null === $input->targetAmount && null === $input->targetAssetCode) {
                    return null;
                }
                if (null === $input->targetAmount || null === $input->targetAssetCode || !AssetCode::fromString($input->targetAssetCode)->equals($goal->assetCode)) {
                    throw new GoalInputError('unsupported_goal_currency', 'A goal target uses the workspace currency.');
                }
                $targetAmount = DecimalValue::fromString($input->targetAmount);
                if (null !== $latest->targetAmount && 0 === $targetAmount->compareTo($latest->targetAmount)) {
                    return null;
                }
                $year = $latest->year ?? throw new \LogicException('An annual version carries a year.');
                if ($year < (int) $today->format('Y')) {
                    throw new GoalInputError('goal_year_elapsed', 'This goal\'s year has already ended; it can no longer be edited.');
                }

                return GoalVersion::annual($this->uuid->generate(), $goal->workspace, $goal->id, $number, $effectiveMonth, $year, $targetAmount, $now, $actorId);
            }
            if (null !== $input->targetAmount || null !== $input->targetAssetCode) {
                throw new GoalInputError('invalid_goal_ratio', 'A rolling goal cannot take a target amount.');
            }
            if (null === $input->ratio) {
                return null;
            }
            $ratio = DecimalValue::fromString($input->ratio);
            if (null !== $latest->ratio && 0 === $ratio->compareTo($latest->ratio)) {
                return null;
            }

            return GoalVersion::rolling($this->uuid->generate(), $goal->workspace, $goal->id, $number, $effectiveMonth, $ratio, $now, $actorId);
        } catch (GoalInputError $exception) {
            throw $exception;
        } catch (InvalidGoal|\InvalidArgumentException|\DomainException $exception) {
            throw new GoalInputError(GoalKind::ANNUAL === $goal->kind ? 'invalid_goal_target' : 'invalid_goal_ratio', $exception->getMessage());
        }
    }
}
