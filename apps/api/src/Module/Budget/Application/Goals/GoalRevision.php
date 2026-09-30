<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Budget\Domain\Goals\GoalVersion;

final readonly class GoalRevision
{
    /** @param list<string> $changedFields */
    public function __construct(public ?GoalVersion $definition, public array $changedFields)
    {
    }
}
