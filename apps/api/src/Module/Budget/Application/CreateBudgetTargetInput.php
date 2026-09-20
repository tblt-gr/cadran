<?php

declare(strict_types=1);

namespace App\Module\Budget\Application;

final readonly class CreateBudgetTargetInput
{
    public function __construct(
        public string $planId,
        public string $scopeType,
        public string $scopeId,
        public string $valueType,
        public ?string $amount,
        public ?string $ratio,
    ) {
    }
}
