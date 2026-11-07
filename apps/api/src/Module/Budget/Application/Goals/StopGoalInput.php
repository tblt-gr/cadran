<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

final readonly class StopGoalInput
{
    public function __construct(public string $goalId, public int $expectedVersion, public string $lastPeriod)
    {
    }
}
