<?php

declare(strict_types=1);

namespace App\Module\Budget\Application;

final readonly class BudgetPlanPage
{
    /** @param list<BudgetPlanView> $items */
    public function __construct(
        public array $items,
        public int $page,
        public int $perPage,
        public int $total,
    ) {
    }
}
