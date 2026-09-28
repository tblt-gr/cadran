<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Budget\Domain\Goals\GoalSchedule;

final readonly class GoalScheduleView
{
    /** @param list<GoalSchedulePartView> $parts */
    public function __construct(public string $mode, public array $parts)
    {
    }

    /** The running totals are computed here, exactly, so no client adds parts up itself. */
    public static function of(GoalSchedule $schedule, ?int $year): self
    {
        $cumulative = $schedule->cumulative();
        $parts = [];
        foreach ($schedule->parts as $index => $part) {
            $parts[] = new GoalSchedulePartView($index + 1, null === $year ? null : sprintf('%04d-%02d', $year, $index + 1), $part->toString(), $cumulative[$index]->toString());
        }

        return new self($schedule->mode->value, $parts);
    }
}
