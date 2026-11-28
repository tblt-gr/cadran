<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Budget\Domain\Goals\GoalEvaluationStatus;
use App\Module\Budget\Domain\Goals\GoalPace;

final readonly class GoalCorrectionPreviewStateView
{
    public function __construct(public ?int $goalVersion, public ?string $expected, public GoalEvaluationStatus $status, public ?GoalPace $pace)
    {
    }
}
