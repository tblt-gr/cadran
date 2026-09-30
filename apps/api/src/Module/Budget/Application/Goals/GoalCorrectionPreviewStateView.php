<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

final readonly class GoalCorrectionPreviewStateView
{
    public function __construct(public ?int $goalVersion, public ?string $expected, public string $status)
    {
    }
}
