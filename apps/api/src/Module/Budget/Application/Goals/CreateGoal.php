<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Budget\Domain\Goals\Goal;
use App\Module\Budget\Domain\Goals\GoalKind;
use App\Module\Budget\Domain\Goals\GoalMetric;
use App\Module\Budget\Domain\Goals\GoalRepository;
use App\Module\Budget\Domain\Goals\GoalScheduleMode;
use App\Module\Budget\Domain\Goals\GoalScopeType;
use App\Module\Budget\Domain\Goals\GoalState;
use App\Module\Budget\Domain\Goals\GoalVersion;
use App\Module\Budget\Domain\Goals\GoalVersionRepository;
use App\Module\Budget\Domain\Goals\InvalidGoal;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\Application\WorkspaceBaseCurrencyReader;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\UuidGenerator;

final readonly class CreateGoal
{
    public function __construct(
        private CallerWorkspaceContext $caller, private GoalRepository $goals, private GoalVersionRepository $versions,
        private ResolveGoalScope $scope, private WorkspaceBaseCurrencyReader $currencies, private WorkspaceCalendar $calendar,
        private UuidGenerator $uuid, private TransactionBoundary $transactions, private RecordAuditEvent $audit, private PresentGoal $present,
        private BuildGoalSchedule $schedules,
    ) {
    }

    public function __invoke(CreateGoalInput $input): GoalDetailView
    {
        $context = $this->caller->resolveContext();
        if (!$context->isOwner) {
            throw new WorkspaceAccessDenied('Only the workspace owner manages goals.');
        }
        [$goal, $definition] = $this->parse($input, $context->workspace, $context->actorId);
        ($this->scope)($context->workspace, $goal->scopeType, $goal->scopeRef, false);

        return $this->transactions->transactional(function () use ($context, $goal, $definition): GoalDetailView {
            $this->goals->lockWorkspace($context->workspace);
            if ($this->goals->countActive($context->workspace) >= 50) {
                throw new GoalConflict('goal_limit_reached', 'A workspace may have at most 50 active goals.');
            }
            $this->goals->add($goal);
            $this->versions->add($definition);
            ($this->audit)(new AuditEventRecord($context->workspace, $context->actorId, GoalAuditEvents::CREATED, GoalAuditEvents::ENTITY, $goal->id, AuditDiff::creation(['kind' => $goal->kind->value, 'metric' => $goal->metric->value, 'scopeType' => $goal->scopeType->value, 'definitionVersion' => 1])));

            return $this->present->detail($goal, [$definition]);
        });
    }

    /** @return array{0: Goal, 1: GoalVersion} */
    private function parse(CreateGoalInput $input, \App\Module\Foundation\Domain\WorkspaceScope $workspace, string $actorId): array
    {
        $kind = GoalKind::tryFrom($input->kind);
        $metric = GoalMetric::tryFrom($input->metric);
        $scope = GoalScopeType::tryFrom($input->scopeType);
        if (null === $kind || null === $metric || null === $scope || !$metric->accepts($kind, $scope)) {
            throw new GoalInputError('invalid_goal_metric_scope', 'The goal metric, kind and scope do not match.');
        }
        $currency = $this->currencies->currency($workspace);
        $now = $this->calendar->now();
        $goalId = $this->uuid->generate();
        try {
            $goal = new Goal($goalId, $workspace, trim($input->label), $kind, $metric, $scope, $input->scopeRef, $currency, GoalState::ACTIVE, 1, $now, $actorId);
        } catch (InvalidGoal $exception) {
            throw new GoalInputError('invalid_goal_target', $exception->getMessage());
        }
        try {
            if (GoalKind::ANNUAL === $kind) {
                $todayYear = (int) $this->calendar->today()->format('Y');
                if (null === $input->year || $input->year < 1900 || $input->year > $todayYear + 5) {
                    throw new GoalInputError('invalid_goal_year', 'A goal year lies from 1900 to five years ahead.');
                }
                if (null === $input->targetAmount || null !== $input->ratio || null !== $input->startMonth) {
                    throw new GoalInputError('invalid_goal_target', 'An annual goal uses a target amount and no ratio.');
                }
                if (null === $input->targetAssetCode || !AssetCode::fromString($input->targetAssetCode)->equals($currency)) {
                    throw new GoalInputError('unsupported_goal_currency', 'A goal target uses the workspace currency.');
                }
                $target = DecimalValue::fromString($input->targetAmount);
                if ($target->compareTo(DecimalValue::zero()) <= 0) {
                    throw new GoalInputError('invalid_goal_target', 'A goal target amount is positive.');
                }
                $schedule = ($this->schedules)($currency, $target, $input->schedule ?? new GoalScheduleInput(GoalScheduleMode::LINEAR->value, null));
                $definition = GoalVersion::annual($this->uuid->generate(), $workspace, $goalId, 1, sprintf('%04d-01', $input->year), $input->year, $target, $schedule, $now, $actorId);
            } else {
                if (null === $input->ratio || null === $input->startMonth || null !== $input->year || null !== $input->targetAmount || null !== $input->schedule) {
                    throw new GoalInputError('invalid_goal_ratio', 'A rolling goal uses a ratio and start month only.');
                }
                CalendarMonth::fromString($input->startMonth);
                $definition = GoalVersion::rolling($this->uuid->generate(), $workspace, $goalId, 1, $input->startMonth, DecimalValue::fromString($input->ratio), $now, $actorId);
            }
        } catch (GoalInputError $exception) {
            throw $exception;
        } catch (InvalidGoal|\InvalidArgumentException|\DomainException $exception) {
            $code = GoalKind::ANNUAL === $kind ? 'invalid_goal_target' : 'invalid_goal_ratio';
            throw new GoalInputError($code, $exception->getMessage());
        }

        return [$goal, $definition];
    }
}
