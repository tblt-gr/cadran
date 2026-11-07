<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\Goals;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\WorkspaceScope;

final readonly class Goal
{
    public function __construct(
        public string $id,
        public WorkspaceScope $workspace,
        public string $label,
        public GoalPeriodicity $periodicity,
        public GoalMetric $metric,
        public GoalScopeType $scopeType,
        public ?string $scopeRef,
        public AssetCode $assetCode,
        public string $startPeriod,
        public ?string $endPeriod,
        public int $version,
        public \DateTimeImmutable $createdAt,
        public string $createdBy,
    ) {
        self::identifier($id);
        self::identifier($createdBy);
        if ($label !== trim($label) || mb_strlen($label) < 1 || mb_strlen($label) > 80) {
            throw new InvalidGoal('A goal label contains between 1 and 80 trimmed characters.');
        }
        if (!$periodicity->accepts($startPeriod) || (null !== $endPeriod && !$periodicity->accepts($endPeriod))) {
            throw new InvalidGoal('A goal period is written YYYY-MM for a monthly goal and YYYY for a yearly one.');
        }
        if (null !== $endPeriod && $endPeriod < $startPeriod) {
            throw new InvalidGoal('A goal end period does not precede its start period.');
        }
        if ((GoalScopeType::WORKSPACE === $scopeType) !== (null === $scopeRef)) {
            throw new InvalidGoal('Only a workspace goal omits its scope reference.');
        }
        if (null !== $scopeRef && ('' === $scopeRef || strlen($scopeRef) > 64)) {
            throw new InvalidGoal('A goal scope reference contains between 1 and 64 characters.');
        }
        if ($version < 1) {
            throw new InvalidGoal('A goal version must be positive.');
        }
    }

    public function direction(): GoalDirection
    {
        return $this->metric->direction();
    }

    /** A goal is current while its end period is not past; a goal without end always is. */
    public function isCurrent(CalendarMonth $today): bool
    {
        return null === $this->endPeriod || $this->endPeriod >= $this->periodOf($today);
    }

    /** Whether the goal covers any month of a period written `YYYY-MM` or `YYYY`. */
    public function coversPeriod(string $period): bool
    {
        if (1 === preg_match('/^[0-9]{4}$/D', $period)) {
            [$from, $to] = [$period.'-01', $period.'-12'];
        } elseif (GoalPeriodicity::MONTHLY->accepts($period)) {
            [$from, $to] = [$period, $period];
        } else {
            throw new InvalidGoal('A period is written YYYY-MM or YYYY.');
        }
        $start = $this->firstMonth();
        $end = $this->lastMonth();

        return $start <= $to && (null === $end || $end >= $from);
    }

    /**
     * The goal ending with this period, written like its start period. A stop never extends a goal
     * and never ends it before it started.
     */
    public function stoppedAt(string $lastPeriod): self
    {
        if (!$this->periodicity->accepts($lastPeriod)) {
            throw new InvalidGoal('A goal period is written YYYY-MM for a monthly goal and YYYY for a yearly one.');
        }
        if ($lastPeriod < $this->startPeriod) {
            throw new InvalidGoal('A goal cannot end before its start period.');
        }
        if (null !== $this->endPeriod && $lastPeriod > $this->endPeriod) {
            throw new InvalidGoal('A goal stop cannot extend its end period.');
        }

        return new self($this->id, $this->workspace, $this->label, $this->periodicity, $this->metric, $this->scopeType, $this->scopeRef, $this->assetCode, $this->startPeriod, $lastPeriod, $this->version + 1, $this->createdAt, $this->createdBy);
    }

    public function reviseLabel(string $label): self
    {
        return $this->with($label);
    }

    public function reviseDefinition(): self
    {
        return $this->with($this->label);
    }

    /** The first covered month, `YYYY-MM`. */
    public function firstMonth(): string
    {
        return GoalPeriodicity::YEARLY === $this->periodicity ? $this->startPeriod.'-01' : $this->startPeriod;
    }

    /** The last covered month, `YYYY-MM`, null without end. */
    public function lastMonth(): ?string
    {
        return null === $this->endPeriod ? null : (GoalPeriodicity::YEARLY === $this->periodicity ? $this->endPeriod.'-12' : $this->endPeriod);
    }

    private function periodOf(CalendarMonth $month): string
    {
        return GoalPeriodicity::YEARLY === $this->periodicity ? (string) $month->year : $month->key();
    }

    private function with(string $label): self
    {
        return new self($this->id, $this->workspace, $label, $this->periodicity, $this->metric, $this->scopeType, $this->scopeRef, $this->assetCode, $this->startPeriod, $this->endPeriod, $this->version + 1, $this->createdAt, $this->createdBy);
    }

    private static function identifier(string $value): void
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value)) {
            throw new InvalidGoal('A goal identifier must be a canonical UUID.');
        }
    }
}
