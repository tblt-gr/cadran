<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\Performance;

use App\Module\Foundation\Domain\CalculatedDecimal;
use App\Module\Portfolio\Domain\Performance\PerformanceFinding;
use App\Module\Portfolio\Domain\Performance\PerformanceQuality;

/** The Modified Dietz return of a period, as the read model exposes it: six-decimal strings, never a float. */
final readonly class ModifiedDietzFigure
{
    /**
     * @param list<PerformanceFinding>                                                                                         $findings blocking reasons first, then warnings
     * @param list<array{operationId: string, date: string, amount: string, remainingDays: string, weight: string}>            $weights
     * @param list<array{month: string, value: ?string, quality: PerformanceQuality, findings: list<PerformanceFinding>}>|null $months   YEAR and INCEPTION only
     */
    public function __construct(
        public ?string $value,
        public PerformanceQuality $quality,
        public array $findings,
        public ?string $averageCapital,
        public array $weights,
        public int $weightCount,
        public ?array $months,
        public ?string $gain = null,
        public ?string $denominatorTimesDays = null,
        public ?CalculatedDecimal $unroundedRate = null,
    ) {
    }
}
