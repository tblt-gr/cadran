<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\Goals;

use App\Module\Accounts\Domain\CalendarMonth;

/** What a goal listing narrows to: a periodicity, a covered period and whether the goal is still current. */
final readonly class GoalFilter
{
    public function __construct(
        public CalendarMonth $today,
        public ?GoalPeriodicity $periodicity = null,
        public ?string $period = null,
        public bool $currentOnly = true,
    ) {
    }
}
