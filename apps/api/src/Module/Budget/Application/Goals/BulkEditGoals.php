<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Budget\Domain\Goals\Goal;
use App\Module\Budget\Domain\Goals\GoalRepository;
use App\Module\Budget\Domain\Goals\GoalVersion;
use App\Module\Budget\Domain\Goals\GoalVersionRepository;
use App\Module\Budget\Domain\Goals\GoalVersionResolver;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Application\WorkspaceAccessDenied;

final readonly class BulkEditGoals
{
    public function __construct(
        private CallerWorkspaceContext $caller, private GoalRepository $goals, private GoalVersionRepository $versions,
        private TransactionBoundary $transactions, private PlanBulkGoalChange $plan, private RecordAuditEvent $audit,
    ) {
    }

    /** @return array{effectiveFrom: string, canApply: bool, items: list<BulkGoalItemView>} */
    public function __invoke(BulkGoalInput $input, bool $save = false): array
    {
        $context = $this->caller->resolveContext();
        if (!$context->isOwner) {
            throw new WorkspaceAccessDenied('Only the workspace owner manages goals.');
        }

        return $this->transactions->transactional(function () use ($input, $save, $context): array {
            // Authorize all identifiers before producing row details. Both operations lock in the
            // same order so competing batches cannot acquire goals in opposite order.
            $items = $input->items;
            usort($items, static fn (array $a, array $b): int => $a['goalId'] <=> $b['goalId']);
            $selected = [];
            foreach ($items as $item) {
                $selected[$item['goalId']] = $this->goals->findForUpdate($context->workspace, $item['goalId'])
                    ?? throw new GoalNotFound('This selection is unavailable in this workspace.');
            }
            $definitions = $this->versions->listByGoals($context->workspace, array_keys($selected));
            $units = [];
            foreach ($selected as $goal) {
                $base = self::base($definitions[$goal->id], $input->effectiveFrom);
                $units[$base->valueType->value.'|'.$goal->assetCode->toString()] = true;
            }
            $compatible = 1 === count($units);
            $candidates = $this->goals->covering($context->workspace, $input->effectiveFrom, '9999-12', 501);
            if (count($candidates) > 500) {
                throw new GoalInputError('goal_preview_limit', 'Too many overlapping goals to preview safely.');
            }
            $rows = [];
            $plans = [];
            foreach ($items as $item) {
                $goal = $selected[$item['goalId']];
                $existing = $definitions[$goal->id];
                $base = self::base($existing, $input->effectiveFrom);
                $revision = null;
                $conflict = null;
                try {
                    $revision = ($this->plan)($goal, $existing, $input, $item['expectedVersion'], $context->actorId, $compatible, $item['expectedValueType'], $item['expectedAssetCode']);
                } catch (GoalInputError|GoalConflict $error) {
                    $field = self::field($error->errorCode);
                    if ($save) {
                        if ($error instanceof GoalConflict) {
                            throw new GoalBulkConflict($error->errorCode, $goal->id, $field);
                        }
                        throw new GoalInputError($error->errorCode, 'A selected goal cannot be changed.', ['goalId' => $goal->id, 'field' => $field]);
                    }
                    $conflict = ['code' => $error->errorCode, 'field' => $field];
                }
                $after = $revision->definition ?? $base;
                $later = array_values(array_filter($existing, static fn (GoalVersion $version): bool => $version->effectiveFrom > $input->effectiveFrom));
                usort($later, static fn (GoalVersion $a, GoalVersion $b): int => $a->effectiveFrom <=> $b->effectiveFrom);
                $last = $goal->lastMonth();
                if ([] !== $later) {
                    $boundary = CalendarMonth::fromString($later[0]->effectiveFrom)->firstDay()->modify('-1 month')->format('Y-m');
                    $last = null === $last ? $boundary : min($last, $boundary);
                }
                $rows[] = new BulkGoalItemView(
                    $goal->id, $goal->label, $goal->version, $base->valueType->value, $goal->assetCode->toString(),
                    self::values($base), null === $conflict ? self::values($after) : ['target' => $input->target ?? self::values($base)['target'], 'tolerance' => $input->tolerance ?? $base->tolerance->toString()], $input->effectiveFrom, $last,
                    array_map(static fn (GoalVersion $version): array => ['versionNo' => $version->versionNo, 'effectiveFrom' => $version->effectiveFrom], $later),
                    self::overrides($goal, $candidates, $last), null !== $revision?->definition, $conflict,
                );
                if (null !== $revision?->definition) {
                    $plans[] = [$goal, $revision];
                }
            }
            if ($save) {
                foreach ($plans as [$goal, $revision]) {
                    $definition = $revision->definition ?? throw new \LogicException('A planned change needs a definition.');
                    $updated = $goal->reviseDefinition();
                    if (!$this->goals->update($updated, $goal->version)) {
                        throw new GoalBulkConflict('stale_goal_version', $goal->id, 'version');
                    }
                    $this->versions->add($definition);
                    ($this->audit)(new AuditEventRecord($context->workspace, $context->actorId, $definition->retroactive ? GoalAuditEvents::CORRECTED : GoalAuditEvents::UPDATED, GoalAuditEvents::ENTITY, $goal->id, AuditDiff::change(
                        ['version' => $goal->version],
                        ['version' => $updated->version, 'definitionVersion' => $definition->versionNo, 'effectiveFrom' => $definition->effectiveFrom, 'changedFields' => implode(',', $revision->changedFields)],
                    )));
                }
            }

            return ['effectiveFrom' => $input->effectiveFrom, 'canApply' => [] === array_filter($rows, static fn (BulkGoalItemView $row): bool => null !== $row->conflict), 'items' => $rows];
        });
    }

    /** @param list<GoalVersion> $versions */
    private static function base(array $versions, string $month): GoalVersion
    {
        return GoalVersionResolver::forMonth($versions, CalendarMonth::fromString($month)) ?? $versions[0];
    }

    /** @return array{target: ?string, tolerance: string} */
    private static function values(GoalVersion $version): array
    {
        return ['target' => ($version->targetAmount ?? $version->ratio)?->toString(), 'tolerance' => $version->tolerance->toString()];
    }

    private static function field(string $code): string
    {
        return match ($code) {
            'invalid_goal_tolerance' => 'tolerance',
            'invalid_goal_target', 'invalid_goal_ratio', 'bulk_custom_schedule_target', 'schedule_precision_exceeded', 'linear_schedule_impossible' => 'target',
            'stale_goal_version' => 'version',
            'correction_reason_required' => 'reason',
            'incompatible_goal_units', 'goal_target_unit_changed' => 'selection',
            default => 'effectiveFrom',
        };
    }

    /**
     * @param list<Goal> $candidates
     *
     * @return list<array{goalId: string, label: string, startPeriod: string, endPeriod: ?string}>
     */
    private static function overrides(Goal $goal, array $candidates, ?string $last): array
    {
        $overrides = [];
        foreach ($candidates as $candidate) {
            if ($goal->id === $candidate->id || $goal->periodicity !== $candidate->periodicity || $goal->metric !== $candidate->metric || $goal->scopeType !== $candidate->scopeType || $goal->scopeRef !== $candidate->scopeRef
                || (null !== $last && $candidate->firstMonth() > $last)
                || [$candidate->startPeriod, $candidate->createdAt, $candidate->id] <= [$goal->startPeriod, $goal->createdAt, $goal->id]) {
                continue;
            }
            $overrides[] = ['goalId' => $candidate->id, 'label' => $candidate->label, 'startPeriod' => $candidate->startPeriod, 'endPeriod' => $candidate->endPeriod];
        }

        return $overrides;
    }
}
