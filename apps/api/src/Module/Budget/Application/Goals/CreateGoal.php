<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Budget\Domain\Goals\Goal;
use App\Module\Budget\Domain\Goals\GoalMetric;
use App\Module\Budget\Domain\Goals\GoalPeriodicity;
use App\Module\Budget\Domain\Goals\GoalRepository;
use App\Module\Budget\Domain\Goals\GoalScheduleMode;
use App\Module\Budget\Domain\Goals\GoalScopeType;
use App\Module\Budget\Domain\Goals\GoalValueType;
use App\Module\Budget\Domain\Goals\GoalVersion;
use App\Module\Budget\Domain\Goals\GoalVersionRepository;
use App\Module\Budget\Domain\Goals\InvalidGoal;
use App\Module\Budget\Domain\Goals\InvalidGoalTolerance;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\Application\WorkspaceBaseCurrencyReader;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\UuidGenerator;
use App\Module\Foundation\Domain\WorkspaceScope;

final readonly class CreateGoal
{
    public const int MAX_CURRENT_GOALS = 100;

    public function __construct(
        private CallerWorkspaceContext $caller, private GoalRepository $goals, private GoalVersionRepository $versions,
        private ResolveGoalScope $scope, private WorkspaceBaseCurrencyReader $currencies, private WorkspaceCalendar $calendar,
        private UuidGenerator $uuid, private TransactionBoundary $transactions, private RecordAuditEvent $audit, private PresentGoal $present,
        private BuildGoalSchedule $schedules, private ResolveOpenEffectiveMonth $openMonth,
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
            if ($this->goals->countCurrent($context->workspace, CalendarMonth::containing($this->calendar->today())) >= self::MAX_CURRENT_GOALS) {
                throw new GoalConflict('goal_limit_reached', sprintf('A workspace may have at most %d current goals.', self::MAX_CURRENT_GOALS));
            }
            // A yearly goal starts at the first open month of its year; a monthly one needs its own month open.
            $last = GoalPeriodicity::YEARLY === $goal->periodicity ? substr($definition->effectiveFrom, 0, 4).'-12' : $definition->effectiveFrom;
            $effective = ($this->openMonth)($context->workspace, $definition->effectiveFrom, $last)
                ?? throw new GoalConflict('goal_closed_period', 'A goal definition cannot take effect in a closed month.');
            $definition = $definition->startingAt($effective);
            $this->goals->add($goal);
            $this->versions->add($definition);
            ($this->audit)(new AuditEventRecord($context->workspace, $context->actorId, GoalAuditEvents::CREATED, GoalAuditEvents::ENTITY, $goal->id, AuditDiff::creation(['periodicity' => $goal->periodicity->value, 'valueType' => $definition->valueType->value, 'metric' => $goal->metric->value, 'scopeType' => $goal->scopeType->value, 'definitionVersion' => 1])));

            return $this->present->detail($goal, [$definition]);
        });
    }

    /** @return array{0: Goal, 1: GoalVersion} */
    private function parse(CreateGoalInput $input, WorkspaceScope $workspace, string $actorId): array
    {
        $periodicity = GoalPeriodicity::tryFrom($input->periodicity);
        $metric = GoalMetric::tryFrom($input->metric);
        $scope = GoalScopeType::tryFrom($input->scopeType);
        if (null === $periodicity || null === $metric || null === $scope) {
            throw new GoalInputError('invalid_goal_metric_scope', 'The goal metric, periodicity and scope do not match.');
        }
        if (!$metric->acceptsScope($scope)) {
            throw new GoalInputError('invalid_goal_metric_scope', 'The goal metric, periodicity and scope do not match.');
        }
        $valueType = GoalValueType::tryFrom($input->valueType);
        if (null === $valueType || !$metric->accepts($periodicity, $valueType, $scope)) {
            throw new GoalInputError('invalid_goal_value_type', 'This goal metric and periodicity do not take this value type.');
        }
        $this->assertPeriods($periodicity, $input->startPeriod, $input->endPeriod);
        $currency = $this->currencies->currency($workspace);
        $now = $this->calendar->now();
        $goalId = $this->uuid->generate();
        try {
            $goal = new Goal($goalId, $workspace, trim($input->label), $periodicity, $metric, $scope, $input->scopeRef, $currency, $input->startPeriod, $input->endPeriod, 1, $now, $actorId);
        } catch (InvalidGoal $exception) {
            throw new GoalInputError('invalid_goal_target', $exception->getMessage());
        }
        $effectiveFrom = GoalPeriodicity::YEARLY === $periodicity ? $input->startPeriod.'-01' : $input->startPeriod;
        $definitionId = $this->uuid->generate();
        $tolerance = GoalToleranceInput::parse($input->tolerance);
        try {
            if (GoalValueType::AMOUNT === $valueType) {
                if (null === $input->targetAmount || null !== $input->ratio) {
                    throw new GoalInputError('invalid_goal_target', 'An amount goal uses a target amount and no ratio.');
                }
                if (null === $input->targetAssetCode || !AssetCode::fromString($input->targetAssetCode)->equals($currency)) {
                    throw new GoalInputError('unsupported_goal_currency', 'A goal target uses the workspace currency.');
                }
                $target = DecimalValue::fromString($input->targetAmount);
                if ($target->compareTo(DecimalValue::zero()) <= 0) {
                    throw new GoalInputError('invalid_goal_target', 'A goal target amount is positive.');
                }
                if (GoalPeriodicity::MONTHLY === $periodicity && null !== $input->schedule) {
                    throw new GoalInputError('invalid_goal_schedule', 'Only a yearly goal has a monthly calendar.');
                }
                $schedule = GoalPeriodicity::YEARLY === $periodicity
                    ? ($this->schedules)($currency, $target, $input->schedule ?? new GoalScheduleInput(GoalScheduleMode::LINEAR->value, null))
                    : null;
                $definition = GoalVersion::amount($definitionId, $workspace, $goalId, 1, $effectiveFrom, $target, $schedule, $now, $actorId, tolerance: $tolerance);
            } else {
                if (null === $input->ratio || null !== $input->targetAmount || null !== $input->schedule) {
                    throw new GoalInputError('invalid_goal_ratio', 'A share or rate goal uses a ratio only.');
                }
                $ratio = DecimalValue::fromString($input->ratio);
                $definition = GoalValueType::RATE === $valueType
                    ? GoalVersion::rate($definitionId, $workspace, $goalId, 1, $effectiveFrom, $ratio, $now, $actorId, tolerance: $tolerance)
                    : GoalVersion::share($definitionId, $workspace, $goalId, 1, $effectiveFrom, $valueType, $ratio, $now, $actorId, tolerance: $tolerance);
            }
        } catch (GoalInputError $exception) {
            throw $exception;
        } catch (InvalidGoalTolerance $exception) {
            throw new GoalInputError('invalid_goal_tolerance', $exception->getMessage());
        } catch (InvalidGoal|\InvalidArgumentException|\DomainException $exception) {
            throw new GoalInputError(GoalValueType::AMOUNT === $valueType ? 'invalid_goal_target' : 'invalid_goal_ratio', $exception->getMessage());
        }

        return [$goal, $definition];
    }

    /** Periods follow the periodicity format, lie from 1900 to five years ahead and never run backwards. */
    private function assertPeriods(GoalPeriodicity $periodicity, string $start, ?string $end): void
    {
        $maxYear = (int) $this->calendar->today()->format('Y') + 5;
        foreach ([$start, $end] as $period) {
            if (null === $period) {
                continue;
            }
            if (!$periodicity->accepts($period) || (int) substr($period, 0, 4) < 1900 || (int) substr($period, 0, 4) > $maxYear) {
                throw new GoalInputError('invalid_goal_period', 'A goal period is YYYY-MM for a monthly goal and YYYY for a yearly one, from 1900 to five years ahead.');
            }
        }
        if (null !== $end && $end < $start) {
            throw new GoalInputError('invalid_goal_period', 'A goal end period does not precede its start period.');
        }
    }
}
