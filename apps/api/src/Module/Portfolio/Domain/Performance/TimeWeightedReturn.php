<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Performance;

use App\Module\Foundation\Domain\CalculatedDecimal;

final readonly class TimeWeightedReturn
{
    /**
     * @param list<array{from: string, to: string, startValue: string, endValue: string, flow: string, value: ?string}> $subperiods
     * @param list<PeriodAnchor>                                                                                        $anchors
     * @param list<PerformanceFinding>                                                                                  $blocking
     * @param list<PerformanceFinding>                                                                                  $warnings
     */
    public function __construct(
        public ?CalculatedDecimal $rate,
        public array $subperiods,
        public int $subperiodCount,
        public int $missingCount,
        public array $blocking,
        public array $warnings,
        public array $anchors = [],
    ) {
    }
}
