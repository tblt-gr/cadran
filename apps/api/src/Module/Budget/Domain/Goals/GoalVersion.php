<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\Goals;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Foundation\Domain\WorkspaceScope;

final readonly class GoalVersion
{
    private function __construct(
        public string $id,
        public WorkspaceScope $workspace,
        public string $goalId,
        public int $versionNo,
        public string $effectiveFrom,
        public ?int $year,
        public ?DecimalValue $targetAmount,
        public ?DecimalValue $ratio,
        public ?GoalSchedule $schedule,
        public ?string $reason,
        public \DateTimeImmutable $createdAt,
        public string $createdBy,
    ) {
        CalendarMonth::fromString($effectiveFrom);
        if ($versionNo < 1) {
            throw new InvalidGoal('A goal definition version number must be positive.');
        }
        if (null !== $reason && mb_strlen($reason) > 200) {
            throw new InvalidGoal('A goal definition reason contains at most 200 characters.');
        }
        if (null !== $targetAmount) {
            self::positiveWithScale($targetAmount, 'target amount');
            if (null === $year || null !== $ratio) {
                throw new InvalidGoal('An annual goal definition carries a year and target amount only.');
            }
            if (null !== $schedule && 0 !== ExactDecimal::sum(...$schedule->parts)->compareTo($targetAmount)) {
                throw new InvalidGoal('An annual goal schedule sums exactly to its target amount.');
            }
        } elseif (null !== $ratio) {
            self::positiveWithScale($ratio, 'ratio');
            if (null !== $schedule) {
                throw new InvalidGoal('A rolling goal definition has no monthly schedule.');
            }
            if (null !== $year || $ratio->compareTo(DecimalValue::fromString('1')) > 0) {
                throw new InvalidGoal('A rolling goal ratio lies in (0, 1].');
            }
        } else {
            throw new InvalidGoal('A goal definition carries a target amount or ratio.');
        }
    }

    /**
     * The schedule is null only for a version written before schedules existed
     * whose target could not be split linearly when it was migrated: its
     * monthly calendar is then not calculable rather than invented.
     */
    public static function annual(string $id, WorkspaceScope $workspace, string $goalId, int $versionNo, string $effectiveFrom, int $year, DecimalValue $targetAmount, ?GoalSchedule $schedule, \DateTimeImmutable $createdAt, string $createdBy): self
    {
        return new self($id, $workspace, $goalId, $versionNo, $effectiveFrom, $year, $targetAmount, null, $schedule, null, $createdAt, $createdBy);
    }

    public static function rolling(string $id, WorkspaceScope $workspace, string $goalId, int $versionNo, string $effectiveFrom, DecimalValue $ratio, \DateTimeImmutable $createdAt, string $createdBy): self
    {
        return new self($id, $workspace, $goalId, $versionNo, $effectiveFrom, null, null, $ratio, null, null, $createdAt, $createdBy);
    }

    public function scheduleMode(): ?string
    {
        return $this->schedule?->mode->value;
    }

    private static function positiveWithScale(DecimalValue $value, string $field): void
    {
        if ($value->compareTo(DecimalValue::zero()) <= 0 || $value->scale() > 8) {
            throw new InvalidGoal(sprintf('A goal %s is positive and carries at most eight decimal places.', $field));
        }
    }
}
