<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

final readonly class GoalScheduleInput
{
    /** @param list<string>|null $parts twelve decimal strings, January first, for a custom schedule only */
    public function __construct(public string $mode, public ?array $parts)
    {
    }
}
