<?php

declare(strict_types=1);

namespace App\Module\Budget\Application;

final readonly class BudgetComparisonView
{
    /** @param list<string> $includedTransactionIds */
    public function __construct(
        public string $targetId,
        public string $scopeType,
        public string $scopeId,
        public ?string $actual,
        public ?string $actualReason,
        public ?string $target,
        public ?string $targetReason,
        public ?string $variance,
        public string $status,
        public array $includedTransactionIds,
        public int $pendingCount,
        public bool $overlapping,
        public string $policy,
    ) {
    }
}
