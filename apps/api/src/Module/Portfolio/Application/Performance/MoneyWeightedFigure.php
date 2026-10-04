<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\Performance;

use App\Module\Portfolio\Domain\Performance\PerformanceFinding;
use App\Module\Portfolio\Domain\Performance\PerformanceQuality;

final readonly class MoneyWeightedFigure
{
    /** @param list<PerformanceFinding> $findings
     * @param list<array{date: string, amount: string}> $cashFlows
     */
    public function __construct(
        public ?string $annualizedRate,
        public ?string $periodRate,
        public ?int $iterations,
        public array $cashFlows,
        public int $cashFlowCount,
        public PerformanceQuality $quality,
        public array $findings,
    ) {
    }
}
