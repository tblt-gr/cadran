<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

final readonly class GoalCorrectionPreviewView
{
    /** @param list<GoalCorrectionPreviewMonthView> $months */
    public function __construct(public array $months)
    {
    }
}
