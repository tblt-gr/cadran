<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

final readonly class GoalVersionView
{
    public function __construct(public int $versionNo, public string $effectiveFrom, public ?int $year, public ?string $targetAmount, public ?string $ratio)
    {
    }
}
