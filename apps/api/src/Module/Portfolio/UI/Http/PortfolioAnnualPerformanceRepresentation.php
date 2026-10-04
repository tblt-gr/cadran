<?php

declare(strict_types=1);

namespace App\Module\Portfolio\UI\Http;

use App\Module\Portfolio\Application\Performance\AnnualPerformanceMonth;
use App\Module\Portfolio\Application\Performance\PortfolioAnnualPerformance;
use App\Module\Portfolio\Domain\Cash\PortfolioCostPolicy;
use App\Module\Portfolio\Domain\Performance\PerformanceMethod;

final class PortfolioAnnualPerformanceRepresentation
{
    /** @return array<string, mixed> */
    public static function of(PortfolioAnnualPerformance $annual): array
    {
        return [
            'accountId' => $annual->accountId,
            'assetCode' => $annual->assetCode,
            'year' => $annual->year,
            'methodVersion' => PerformanceMethod::VERSION,
            'costPolicy' => PortfolioCostPolicy::PERFORMANCE_BASIS,
            'availableYears' => ['from' => $annual->firstYear, 'to' => $annual->lastYear],
            'months' => array_map(self::month(...), $annual->months),
            'yearTotal' => PortfolioPerformanceRepresentation::line($annual->yearTotal),
            'inception' => PortfolioPerformanceRepresentation::line($annual->inception),
        ];
    }

    /** @return array<string, mixed> */
    private static function month(AnnualPerformanceMonth $month): array
    {
        if (null === $month->performance) {
            return ['month' => $month->month, 'status' => $month->status];
        }

        return [
            'month' => $month->month,
            'status' => $month->status,
            ...PortfolioPerformanceRepresentation::line($month->performance),
            'endCash' => $month->endCash,
            'modifiedDietzIndex' => $month->modifiedDietzIndex,
        ];
    }
}
