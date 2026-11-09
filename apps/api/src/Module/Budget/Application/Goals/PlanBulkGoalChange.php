<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Budget\Domain\Goals\Goal;
use App\Module\Budget\Domain\Goals\GoalScheduleMode;
use App\Module\Budget\Domain\Goals\GoalValueType;
use App\Module\Budget\Domain\Goals\GoalVersion;
use App\Module\Budget\Domain\Goals\GoalVersionResolver;

final readonly class PlanBulkGoalChange
{
    public function __construct(private BuildGoalRevision $revision, private ResolveOpenEffectiveMonth $openMonth)
    {
    }

    /** @param list<GoalVersion> $versions */
    public function __invoke(Goal $goal, array $versions, BulkGoalInput $input, int $expectedVersion, string $actorId, bool $compatible, string $expectedValueType, string $expectedAssetCode): GoalRevision
    {
        if ($goal->version !== $expectedVersion) {
            throw new GoalConflict('stale_goal_version', 'Refresh the selected goals.');
        }
        if (!$compatible) {
            throw new GoalInputError('incompatible_goal_units', 'Selected goals must share a value type and target unit.');
        }
        if ($input->effectiveFrom < $goal->firstMonth() || (null !== $goal->lastMonth() && $input->effectiveFrom > $goal->lastMonth())) {
            throw new GoalInputError('invalid_effective_month', 'The selected goal must cover the effective month.');
        }
        $base = GoalVersionResolver::forMonth($versions, CalendarMonth::fromString($input->effectiveFrom)) ?? $versions[0];
        if ($base->valueType->value !== $expectedValueType || $goal->assetCode->toString() !== $expectedAssetCode) {
            throw new GoalConflict('goal_target_unit_changed', 'The selected target unit differs at the effective month; refresh the selection.');
        }
        if (null !== $input->target && GoalScheduleMode::CUSTOM === $base->schedule?->mode) {
            throw new GoalInputError('bulk_custom_schedule_target', 'Edit custom annual targets individually.');
        }
        // Even an unchanged batch must not acknowledge a closed effective month as writable.
        if (null === ($this->openMonth)($goal->workspace, $input->effectiveFrom, $input->effectiveFrom)) {
            throw new GoalConflict('goal_closed_period', 'The effective month is closed.');
        }
        $amount = GoalValueType::AMOUNT === $base->valueType;
        $revision = ($this->revision)($goal, $versions, new UpdateGoalInput(
            $goal->id, $expectedVersion, $goal->label,
            $amount ? $input->target : null,
            $amount && null !== $input->target ? $goal->assetCode->toString() : null,
            $amount ? null : $input->target, false,
            effectiveFrom: $input->effectiveFrom, reason: $input->reason, tolerance: $input->tolerance,
        ), $actorId);
        if (null !== $revision->definition && count($versions) >= 50) {
            throw new GoalConflict('goal_version_limit', 'A goal may have at most 50 versions.');
        }

        return $revision;
    }
}
