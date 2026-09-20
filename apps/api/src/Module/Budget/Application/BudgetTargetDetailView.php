<?php

declare(strict_types=1);

namespace App\Module\Budget\Application;

final readonly class BudgetTargetDetailView
{
    public function __construct(
        public string $id,
        public string $scopeType,
        public string $scopeId,
        public string $valueType,
        public ?string $storedAmount,
        public ?string $storedRatio,
        public ?string $resolvedAmount,
        public ?string $nonCalculableReason,
        public bool $overlapping,
        public int $version,
    ) {
    }
}
