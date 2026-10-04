<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\Performance;

/** The twelve months of a year, the year and the whole life of a portfolio account, from one read of its history. */
final readonly class PortfolioAnnualPerformance
{
    /** @param list<AnnualPerformanceMonth> $months */
    public function __construct(
        public string $accountId,
        public string $assetCode,
        public int $year,
        public int $firstYear,
        public int $lastYear,
        public array $months,
        public PortfolioPerformance $yearTotal,
        public PortfolioPerformance $inception,
    ) {
    }
}
