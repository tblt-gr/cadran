<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

final readonly class CreateGoalInput
{
    public function __construct(
        public string $label,
        public string $kind,
        public string $metric,
        public string $scopeType,
        public ?string $scopeRef,
        public ?int $year,
        public ?string $targetAmount,
        public ?string $targetAssetCode,
        public ?string $ratio,
        public ?string $startMonth,
    ) {
    }
}
