<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Performance;

use App\Module\Foundation\Domain\CalculatedDecimal;

/** The Modified Dietz return of one month with the figures that explain it; the rate keeps its scale-24 precision. */
final readonly class ModifiedDietz
{
    /**
     * @param list<array{operationId: string, date: string, amount: string, remainingDays: string, weight: string}> $weights              the first ModifiedDietzCalculator::MAX_WEIGHTS flows
     * @param list<PerformanceFinding>                                                                              $blocking
     * @param list<PerformanceFinding>                                                                              $warnings
     * @param ?string                                                                                               $gain                 end - start - net flows, canonical
     * @param ?string                                                                                               $denominatorTimesDays start x days + sum(flow x remaining days), canonical
     */
    public function __construct(
        public ?CalculatedDecimal $rate,
        public ?string $averageCapital,
        public ?PerformanceReason $reason,
        public array $weights,
        public int $weightCount,
        public array $blocking,
        public array $warnings,
        public ?string $gain = null,
        public ?string $denominatorTimesDays = null,
    ) {
    }
}
