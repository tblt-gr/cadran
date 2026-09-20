<?php

declare(strict_types=1);

namespace App\Module\Budget\Application;

use App\Module\Reporting\Application\MonthlyBudgetSourceView;

final readonly class BudgetComparisonView
{
    /** @param list<MonthlyBudgetSourceView> $includedTransactions */
    public function __construct(
        public string $targetId,
        public string $scopeType,
        public string $scopeId,
        public string $scopeLabel,
        public ?string $actual,
        public ?string $actualReason,
        public ?string $target,
        public ?string $targetReason,
        public ?string $variance,
        public string $status,
        public array $includedTransactions,
        public int $pendingCount,
        public bool $overlapping,
        public string $policy,
    ) {
    }
}
