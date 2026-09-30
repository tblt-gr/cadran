<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

final readonly class GoalCorrectionPreviewMonthView
{
    public function __construct(public string $month, public GoalCorrectionPreviewStateView $before, public GoalCorrectionPreviewStateView $after)
    {
    }
}
