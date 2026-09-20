<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain;

final readonly class BudgetComparisonResult
{
    public function __construct(
        public ?string $actual,
        public ?string $target,
        public ?string $variance,
        public string $status,
        public ?BudgetComparisonReason $reason,
    ) {
    }
}
