<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\Goals;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Foundation\Domain\WorkspaceScope;

final readonly class GoalVersion
{
    /** The margin accepted around the target, in the unit of the target; zero keeps the goal strict. */
    public DecimalValue $tolerance;

    private function __construct(
        public string $id,
        public WorkspaceScope $workspace,
        public string $goalId,
        public int $versionNo,
        public string $effectiveFrom,
        public GoalValueType $valueType,
        public ?DecimalValue $targetAmount,
        public ?DecimalValue $ratio,
        public ?GoalSchedule $schedule,
        public ?string $reason,
        public \DateTimeImmutable $createdAt,
        public string $createdBy,
        public bool $retroactive = false,
        ?DecimalValue $tolerance = null,
    ) {
        $this->tolerance = $tolerance ?? DecimalValue::zero();
        CalendarMonth::fromString($effectiveFrom);
        if ($versionNo < 1) {
            throw new InvalidGoal('A goal definition version number must be positive.');
        }
        if (null !== $reason && ($reason !== trim($reason) || mb_strlen($reason) < 3 || mb_strlen($reason) > 200)) {
            throw new InvalidGoal('A correction reason contains between 3 and 200 trimmed characters.');
        }
        if ($retroactive !== (null !== $reason)) {
            throw new InvalidGoal('Only a retroactive goal definition carries a correction reason.');
        }
        if ($this->tolerance->scale() > 8) {
            throw new InvalidGoalTolerance('A goal tolerance carries at most eight decimal places.');
        }
        if ($this->tolerance->isNegative()) {
            throw new InvalidGoalTolerance('A goal tolerance is not negative.');
        }
        if (GoalValueType::AMOUNT === $valueType) {
            if (null === $targetAmount || null !== $ratio) {
                throw new InvalidGoal('An amount goal definition carries a target amount only.');
            }
            self::scaled($targetAmount, 'target amount');
            if ($targetAmount->compareTo(DecimalValue::zero()) <= 0) {
                throw new InvalidGoal('A goal target amount is positive.');
            }
            if ($this->tolerance->compareTo($targetAmount) > 0) {
                throw new InvalidGoalTolerance('A goal tolerance does not exceed its target.');
            }
            if (null !== $schedule && 0 !== ExactDecimal::sum(...$schedule->parts)->compareTo($targetAmount)) {
                throw new InvalidGoal('A goal schedule sums exactly to its target amount.');
            }

            return;
        }
        if (null !== $targetAmount || null === $ratio || null !== $schedule) {
            throw new InvalidGoal('A share or rate goal definition carries a ratio and no amount or schedule.');
        }
        self::scaled($ratio, 'ratio');
        // A savings rate of zero is a meaningful floor; a share of zero is not a target.
        if ($this->tolerance->compareTo($ratio) > 0) {
            throw new InvalidGoalTolerance('A goal tolerance does not exceed its target.');
        }
        $floor = GoalValueType::RATE === $valueType ? $ratio->compareTo(DecimalValue::zero()) < 0 : $ratio->compareTo(DecimalValue::zero()) <= 0;
        if ($floor || $ratio->compareTo(DecimalValue::fromString('1')) > 0) {
            throw new InvalidGoal(GoalValueType::RATE === $valueType ? 'A goal rate lies in [0, 1].' : 'A goal share lies in (0, 1].');
        }
    }

    /**
     * The schedule is null only for a version written before schedules existed
     * whose target could not be split linearly when it was migrated: its
     * monthly calendar is then not calculable rather than invented.
     */
    public static function amount(string $id, WorkspaceScope $workspace, string $goalId, int $versionNo, string $effectiveFrom, DecimalValue $targetAmount, ?GoalSchedule $schedule, \DateTimeImmutable $createdAt, string $createdBy, bool $retroactive = false, ?string $reason = null, ?DecimalValue $tolerance = null): self
    {
        return new self($id, $workspace, $goalId, $versionNo, $effectiveFrom, GoalValueType::AMOUNT, $targetAmount, null, $schedule, $reason, $createdAt, $createdBy, $retroactive, $tolerance);
    }

    /** A share of the period's cash income or of the rolling average income. */
    public static function share(string $id, WorkspaceScope $workspace, string $goalId, int $versionNo, string $effectiveFrom, GoalValueType $valueType, DecimalValue $ratio, \DateTimeImmutable $createdAt, string $createdBy, bool $retroactive = false, ?string $reason = null, ?DecimalValue $tolerance = null): self
    {
        if (GoalValueType::INCOME_SHARE !== $valueType && GoalValueType::ROLLING_INCOME_SHARE !== $valueType) {
            throw new InvalidGoal('A share definition uses an income share value type.');
        }

        return new self($id, $workspace, $goalId, $versionNo, $effectiveFrom, $valueType, null, $ratio, null, $reason, $createdAt, $createdBy, $retroactive, $tolerance);
    }

    public static function rate(string $id, WorkspaceScope $workspace, string $goalId, int $versionNo, string $effectiveFrom, DecimalValue $ratio, \DateTimeImmutable $createdAt, string $createdBy, bool $retroactive = false, ?string $reason = null, ?DecimalValue $tolerance = null): self
    {
        return new self($id, $workspace, $goalId, $versionNo, $effectiveFrom, GoalValueType::RATE, null, $ratio, null, $reason, $createdAt, $createdBy, $retroactive, $tolerance);
    }

    /** The same definition taking effect in another month. */
    public function startingAt(string $effectiveFrom): self
    {
        return new self($this->id, $this->workspace, $this->goalId, $this->versionNo, $effectiveFrom, $this->valueType, $this->targetAmount, $this->ratio, $this->schedule, $this->reason, $this->createdAt, $this->createdBy, $this->retroactive, $this->tolerance);
    }

    /**
     * The margin in the unit of the evaluated target, null when strict. A share converts it with the
     * income that gave its target, so the bound of a share is exact whatever the income.
     */
    public function toleranceAmount(?string $income): ?string
    {
        if ($this->tolerance->compareTo(DecimalValue::zero()) <= 0) {
            return null;
        }
        if (GoalValueType::AMOUNT === $this->valueType || GoalValueType::RATE === $this->valueType) {
            return $this->tolerance->toString();
        }

        return null === $income ? null : ExactDecimal::multiplyForResponse(DecimalValue::fromString($income), $this->tolerance);
    }

    public function scheduleMode(): ?string
    {
        return $this->schedule?->mode->value;
    }

    private static function scaled(DecimalValue $value, string $field): void
    {
        if ($value->scale() > 8) {
            throw new InvalidGoal(sprintf('A goal %s carries at most eight decimal places.', $field));
        }
    }
}
