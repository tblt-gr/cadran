<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

final readonly class GoalEvaluationsView
{
    /**
     * @param list<GoalPeriodEvaluationView> $months
     * @param list<GoalPeriodEvaluationView> $years
     */
    public function __construct(public array $months, public array $years)
    {
    }
}
