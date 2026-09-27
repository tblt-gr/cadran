<?php

declare(strict_types=1);

namespace App\Module\Demo\Application;

final readonly class DemoSeedSummary
{
    public function __construct(
        public int $accounts,
        public int $categories,
        public int $transactions,
        public int $transfers,
        public int $closedMonths,
        public int $budgetPlans,
    ) {
    }
}
