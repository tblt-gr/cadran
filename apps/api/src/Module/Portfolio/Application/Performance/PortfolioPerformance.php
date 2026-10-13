<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\Performance;

use App\Module\Portfolio\Domain\Performance\GainDecomposition;
use App\Module\Portfolio\Domain\Performance\PerformancePeriod;
use App\Module\Portfolio\Domain\Performance\PeriodAnchor;
use App\Module\Portfolio\Domain\Performance\TimeWeightedReturn;

/** The read model of the performance of one account over one period. */
final readonly class PortfolioPerformance
{
    /**
     * @param list<array{operationId: string, type: string, date: string, amount: ?string}> $flows        the first ComputePortfolioPerformance::MAX_FLOWS external flows
     * @param array<string, PerformanceExplanation>                                         $explanations by PerformanceMethod value
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
        public GainDecomposition $decomposition,
        public array $flows,
        public int $flowCount,
        public ModifiedDietzFigure $modifiedDietz,
        public TimeWeightedReturn $timeWeighted,
        public MoneyWeightedFigure $moneyWeighted,
        public array $explanations,
        public ?string $deposits = null,
        public ?string $withdrawals = null,
    ) {
    }
}
