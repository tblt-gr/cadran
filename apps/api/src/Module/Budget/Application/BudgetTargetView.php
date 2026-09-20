<?php

declare(strict_types=1);

namespace App\Module\Budget\Application;

final readonly class BudgetTargetView
{
    public function __construct(
        public string $id,
        public string $planId,
        public string $scopeType,
        public string $scopeId,
        public string $valueType,
        public ?string $amount,
        public ?string $ratio,
        public int $version,
    ) {
    }
}
