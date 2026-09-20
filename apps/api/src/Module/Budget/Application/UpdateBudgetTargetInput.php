<?php

declare(strict_types=1);

namespace App\Module\Budget\Application;

final readonly class UpdateBudgetTargetInput
{
    public function __construct(
        public string $targetId,
        public int $expectedVersion,
        public string $valueType,
        public ?string $amount,
        public ?string $ratio,
    ) {
    }
}
