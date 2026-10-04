<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\Performance;

use App\Module\Portfolio\Domain\Performance\GainDecomposition;
use App\Module\Portfolio\Domain\Performance\PerformanceFinding;
use App\Module\Portfolio\Domain\Performance\PerformancePeriod;
use App\Module\Portfolio\Domain\Performance\PerformanceQuality;
use App\Module\Portfolio\Domain\Performance\PeriodAnchor;

/** The read model of the performance of one account over one period. */
final readonly class PortfolioPerformance
{
    /**
     * @param list<PerformanceFinding>                                                      $findings blocking reasons first, then warnings
     * @param list<array{operationId: string, type: string, date: string, amount: ?string}> $flows    the first ReadPortfolioPerformance::MAX_FLOWS external flows
     * @param list<array{kind: string, id: string, date: string}>                           $sources  the first PortfolioExplanation::MAX_SOURCES sources, anchors first
     */
    public function __construct(
        public string $accountId,
        public string $assetCode,
        public PerformancePeriod $period,
        public ?PeriodAnchor $start,
        public ?PeriodAnchor $end,
        public int $displayPrecision,
        public ?string $gain,
        public ?string $netFlows,
        public ?string $startValue,
        public ?string $endValue,
        public PerformanceQuality $quality,
        public array $findings,
        public GainDecomposition $decomposition,
        public array $flows,
        public int $flowCount,
        public array $sources,
        public int $sourceCount,
    ) {
    }
}
