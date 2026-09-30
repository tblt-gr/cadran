<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

final readonly class GoalVersionChangeView
{
    public function __construct(public string $field, public mixed $before, public mixed $after)
    {
    }
}
