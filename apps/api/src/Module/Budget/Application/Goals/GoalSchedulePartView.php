<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

final readonly class GoalSchedulePartView
{
    public function __construct(public int $monthIndex, public ?string $month, public string $amount, public string $cumulative)
    {
    }
}
