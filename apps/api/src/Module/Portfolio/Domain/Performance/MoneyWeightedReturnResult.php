<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Performance;

final readonly class MoneyWeightedReturnResult
{
    public function __construct(
        public ?string $annualizedRate,
        public ?string $periodRate,
        public ?PerformanceReason $reason,
        public ?int $iterations,
        public bool $uniqueRoot = false,
    ) {
    }
}
