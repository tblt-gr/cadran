<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

final readonly class MonthlyBudgetActualView
{
    /** @param list<string> $transactionIds */
    public function __construct(
        public string $scopeKey,
        public MonthlyMetricView $amount,
        public array $transactionIds,
        public int $pendingCount,
    ) {
    }
}
