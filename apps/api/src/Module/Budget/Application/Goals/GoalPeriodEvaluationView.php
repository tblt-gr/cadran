<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

final readonly class GoalPeriodEvaluationView
{
    /** @param list<GoalEvaluationView> $items */
    public function __construct(public string $period, public array $items)
    {
    }
}
