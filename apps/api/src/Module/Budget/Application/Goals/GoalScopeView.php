<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

final readonly class GoalScopeView
{
    public function __construct(public string $type, public ?string $id, public ?string $axis, public string $label, public bool $archived)
    {
    }
}
