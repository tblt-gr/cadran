<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

final readonly class GoalVersionHistoryView
{
    /** @param list<GoalVersionHistoryItemView> $items */
    public function __construct(public array $items)
    {
    }
}
