<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Accounts\Domain\InvalidCalendarMonth;
use App\Module\Budget\Domain\Goals\Goal;
use App\Module\Budget\Domain\Goals\GoalKind;
use App\Module\Budget\Domain\Goals\GoalScheduleMode;
use App\Module\Budget\Domain\Goals\GoalVersion;
use App\Module\Budget\Domain\Goals\GoalVersionResolver;
use App\Module\Budget\Domain\Goals\InvalidGoal;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\UuidGenerator;

final readonly class BuildGoalRevision
{
    public const MAX_CORRECTION_MONTHS = 120;

    public function __construct(private WorkspaceCalendar $calendar, private UuidGenerator $uuid, private BuildGoalSchedule $schedules)
    {
    }

    /** @param list<GoalVersion> $versions */
    public function __invoke(Goal $goal, array $versions, UpdateGoalInput $input, string $actorId): GoalRevision
    {
        if ($input->identitySubmitted) {
            throw new GoalInputError('goal_identity_immutable', 'A goal metric and scope cannot change.');
        }
        $label = trim($input->label);
        if ('' === $label || mb_strlen($label) > 80) {
            throw new GoalInputError('invalid_goal_target', 'A goal label contains between 1 and 80 trimmed characters.');
        }
        $latest = $versions[0] ?? throw new \LogicException('A goal must have a definition.');
        $firstMonth = min(array_map(static fn (GoalVersion $version): string => $version->effectiveFrom, $versions));
        $today = $this->calendar->today();
        $currentMonth = CalendarMonth::containing($today)->key();
        $effectiveMonth = $input->effectiveFrom ?? ($currentMonth > $firstMonth ? $currentMonth : $firstMonth);
        $explicitMonth = null !== $input->effectiveFrom;
        try {
            $effective = CalendarMonth::fromString($effectiveMonth);
        } catch (InvalidCalendarMonth) {
            throw new GoalInputError('invalid_effective_month', 'A goal effective month is written YYYY-MM.');
        }
        if ($effectiveMonth < $firstMonth || ($effectiveMonth > $currentMonth && ($explicitMonth || $firstMonth <= $currentMonth))) {
            throw new GoalInputError('invalid_effective_month', 'A goal effective month lies from its first month through the current month.');
        }
        // Each month of a rolling correction is recomputed by the preview, so the span is bounded here,
        // where the preview and the save share their validation. An annual goal stops at its own year.
        $span = ((int) substr($currentMonth, 0, 4) - (int) substr($effectiveMonth, 0, 4)) * 12
            + (int) substr($currentMonth, 5, 2) - (int) substr($effectiveMonth, 5, 2) + 1;
        if (GoalKind::ANNUAL !== $goal->kind && $span > self::MAX_CORRECTION_MONTHS) {
            throw new GoalInputError('correction_span_exceeded', sprintf('A correction reaches back at most %d months.', self::MAX_CORRECTION_MONTHS));
        }
        $retroactive = $effectiveMonth < $currentMonth;
        $reason = null === $input->reason ? null : trim($input->reason);
        if ($retroactive && (null === $reason || mb_strlen($reason) < 3 || mb_strlen($reason) > 200)) {
            throw new GoalInputError('correction_reason_required', 'A retroactive correction needs a reason between 3 and 200 characters.');
        }
        if (!$retroactive) {
            $reason = null;
        }

        $base = GoalVersionResolver::forMonth($versions, $effective) ?? $latest;
        try {
            $definition = GoalKind::ANNUAL === $goal->kind
                ? $this->annual($goal, $base, $input, $latest->versionNo + 1, $effectiveMonth, $actorId, $retroactive, $reason)
                : $this->rolling($goal, $base, $input, $latest->versionNo + 1, $effectiveMonth, $actorId, $retroactive, $reason);
        } catch (GoalInputError $exception) {
            throw $exception;
        } catch (InvalidGoal|\InvalidArgumentException|\DomainException $exception) {
            throw new GoalInputError(GoalKind::ANNUAL === $goal->kind ? 'invalid_goal_target' : 'invalid_goal_ratio', $exception->getMessage());
        }
        if (null !== $definition && $effectiveMonth > $currentMonth) {
            throw new GoalInputError('invalid_effective_month', 'A goal definition cannot take effect after the current month.');
        }

        return new GoalRevision($definition, self::changedFields($base, $definition));
    }

    private function rolling(Goal $goal, GoalVersion $base, UpdateGoalInput $input, int $number, string $effectiveMonth, string $actorId, bool $retroactive, ?string $reason): ?GoalVersion
    {
        if (null !== $input->targetAmount || null !== $input->targetAssetCode || null !== $input->schedule) {
            throw new GoalInputError('invalid_goal_ratio', 'A rolling goal cannot take a target amount or schedule.');
        }
        if (null === $input->ratio) {
            return null;
        }
        $ratio = DecimalValue::fromString($input->ratio);
        if (null !== $base->ratio && 0 === $ratio->compareTo($base->ratio)) {
            return null;
        }

        return GoalVersion::rolling($this->uuid->generate(), $goal->workspace, $goal->id, $number, $effectiveMonth, $ratio, $this->calendar->now(), $actorId, $retroactive, $reason);
    }

    private function annual(Goal $goal, GoalVersion $base, UpdateGoalInput $input, int $number, string $effectiveMonth, string $actorId, bool $retroactive, ?string $reason): ?GoalVersion
    {
        if (null !== $input->ratio) {
            throw new GoalInputError('invalid_goal_target', 'An annual goal cannot take a ratio.');
        }
        $baseTarget = $base->targetAmount ?? throw new \LogicException('An annual version carries a target amount.');
        $target = $baseTarget;
        if (null !== $input->targetAmount || null !== $input->targetAssetCode) {
            if (null === $input->targetAmount || null === $input->targetAssetCode || !AssetCode::fromString($input->targetAssetCode)->equals($goal->assetCode)) {
                throw new GoalInputError('unsupported_goal_currency', 'A goal target uses the workspace currency.');
            }
            $target = DecimalValue::fromString($input->targetAmount);
        }
        $targetChanged = 0 !== $target->compareTo($baseTarget);
        $schedule = null;
        $scheduleError = null;
        if (null !== $input->schedule) {
            try {
                $schedule = ($this->schedules)($goal->assetCode, $target, $input->schedule);
            } catch (GoalInputError $error) {
                $scheduleError = $error;
            }
        }
        if (!$targetChanged) {
            if (null === $input->schedule) {
                return null;
            }
            if (null !== $schedule && null !== $base->schedule && $schedule->sameAs($base->schedule)) {
                return null;
            }
            if (null !== $scheduleError
                && null === $base->schedule
                && trim($input->label) !== $goal->label
                && GoalScheduleMode::LINEAR->value === $input->schedule->mode
                && null === $input->schedule->parts
                && in_array($scheduleError->errorCode, ['schedule_precision_exceeded', 'linear_schedule_impossible'], true)
            ) {
                return null;
            }
        }
        if (null !== $scheduleError) {
            throw $scheduleError;
        }
        $year = $base->year ?? throw new \LogicException('An annual version carries a year.');
        if ((int) substr($effectiveMonth, 0, 4) !== $year) {
            throw new GoalInputError('invalid_effective_month', 'An annual goal effective month belongs to its year.');
        }
        if ($target->compareTo(DecimalValue::zero()) <= 0) {
            throw new GoalInputError('invalid_goal_target', 'A goal target amount is positive.');
        }
        if (null === $input->schedule && GoalScheduleMode::CUSTOM === $base->schedule?->mode) {
            throw new GoalInputError('schedule_part_count', 'A custom schedule needs its twelve new parts when the target changes.');
        }
        $schedule ??= $this->schedules->linear($goal->assetCode, $target);

        return GoalVersion::annual($this->uuid->generate(), $goal->workspace, $goal->id, $number, $effectiveMonth, $year, $target, $schedule, $this->calendar->now(), $actorId, $retroactive, $reason);
    }

    /** @return list<string> */
    public static function changedFields(GoalVersion $before, ?GoalVersion $after): array
    {
        if (null === $after) {
            return [];
        }
        $fields = [];
        if ((null === $before->targetAmount) !== (null === $after->targetAmount) || (null !== $before->targetAmount && null !== $after->targetAmount && 0 !== $before->targetAmount->compareTo($after->targetAmount))) {
            $fields[] = 'targetAmount';
        }
        if ((null === $before->ratio) !== (null === $after->ratio) || (null !== $before->ratio && null !== $after->ratio && 0 !== $before->ratio->compareTo($after->ratio))) {
            $fields[] = 'ratio';
        }
        if ($before->scheduleMode() !== $after->scheduleMode()) {
            $fields[] = 'scheduleMode';
        }
        if ((null === $before->schedule) !== (null === $after->schedule) || (null !== $before->schedule && null !== $after->schedule && !$before->schedule->sameAs($after->schedule))) {
            $fields[] = 'schedule';
        }

        return $fields;
    }
}
