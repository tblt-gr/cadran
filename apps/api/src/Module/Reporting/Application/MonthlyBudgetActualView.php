<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

final readonly class MonthlyBudgetActualView
{
    /** @param list<MonthlyBudgetSourceView> $sources */
    public function __construct(
        public string $scopeKey,
        public MonthlyMetricView $amount,
        public array $sources,
        public int $pendingCount,
    ) {
    }
}
