<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

final readonly class GoalPage
{
    /** @param list<GoalSummaryView> $items */
    public function __construct(public array $items, public int $page, public int $pageSize, public int $total)
    {
    }
}
