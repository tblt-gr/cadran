<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application\Facade;

use App\Module\Reporting\Application\AnnualColumn;
use App\Module\Reporting\Application\AnnualColumnSeries;
use App\Module\Reporting\Application\AnnualMonth;
use App\Module\Reporting\Domain\Aggregation\Aggregate;
use App\Module\Reporting\Domain\Aggregation\IncompleteMonths;
use App\Module\Reporting\Domain\Aggregation\MonthValue;

/** One figure over several months, read and aggregated exactly as the reports do. */
final readonly class ReportFigureSeries
{
    /**
     * @param list<AnnualMonth> $months
     *
     * @return list<MonthValue> a month without movement counts as an exact zero
     */
    public static function values(ReportFigureKey $key, array $months): array
    {
        return AnnualColumnSeries::values($key->id, true, $months);
    }

    /**
     * Flows are summed; rates are recomputed from their summed numerator and denominator.
     *
     * @param list<AnnualMonth> $months
     */
    public static function aggregate(ReportFigureKey $key, array $months, IncompleteMonths $incomplete): Aggregate
    {
        return AnnualColumnSeries::aggregate(new AnnualColumn($key->id, '', $key->kind, true), $months, $incomplete);
    }
}
