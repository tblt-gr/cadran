<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Performance;

final readonly class TotalGain
{
    /**
     * @param list<PerformanceFinding> $blocking
     * @param list<PerformanceFinding> $warnings
     */
    public function __construct(
        public ?string $gain,
        public ?string $netFlows,
        public string $startValue,
        public string $endValue,
        public array $blocking,
        public array $warnings,
        public GainDecomposition $decomposition,
    ) {
    }
}
