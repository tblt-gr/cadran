<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Reporting\Domain\Aggregation\Aggregate;
use App\Module\Reporting\Domain\Aggregation\ColumnKind;
use App\Module\Reporting\Domain\Aggregation\IncompleteMonths;
use App\Module\Reporting\Domain\Aggregation\MonthValue;
use App\Module\Reporting\Domain\Aggregation\RateInputs;
use App\Module\Reporting\Domain\Aggregation\ReportAggregator;

/** Turns the months of a year into the values of one column and aggregates them with the shared engine. */
final class AnnualColumnSeries
{
    public const string UNKNOWN_REFERENCE = 'UNKNOWN_REFERENCE';
    public const string NO_MOVEMENTS = 'NO_MOVEMENTS';
    public const string NOT_IN_PROJECTION = 'NOT_IN_PROJECTION';

    /** numerator and denominator column of each rate column */
    private const array RATE_SOURCES = [
        'cashSavingsRate' => ['budgetSurplus', 'cashIncome'],
        'netSavingsRate' => ['netSavingsTransfers', 'cashIncome'],
    ];

    /** @param list<AnnualMonth> $months */
    public static function aggregate(AnnualColumn $column, array $months, IncompleteMonths $incomplete): Aggregate
    {
        if (ColumnKind::RATE !== $column->kind) {
            $values = self::values($column->id, $column->known, $months);

            return ColumnKind::STOCK === $column->kind
                ? ReportAggregator::stock($values, $incomplete)
                : ReportAggregator::flow($values, $incomplete);
        }
        [$numerator, $denominator] = self::RATE_SOURCES[$column->id];

        return ReportAggregator::rate(
            new RateInputs(self::values($numerator, true, $months), self::values($denominator, true, $months)),
            self::values($column->id, true, $months),
            $incomplete,
        );
    }

    public static function cell(AnnualColumn $column, AnnualMonth $month): AnnualCellView
    {
        $value = self::values($column->id, $column->known, [$month], false)[0];

        return new AnnualCellView($value->value?->toString(), $value->reason);
    }

    /**
     * The asset of the first figure the column has in the year, or null for a rate or an empty column.
     *
     * @param list<AnnualMonth> $months
     */
    public static function assetCode(AnnualColumn $column, array $months): ?string
    {
        if (ColumnKind::RATE === $column->kind) {
            return null;
        }
        foreach ($months as $month) {
            $asset = $month->figures?->cells[$column->id]->assetCode ?? null;
            if (null !== $asset) {
                return $asset;
            }
        }

        return null;
    }

    /**
     * @param list<AnnualMonth> $months
     *
     * @return list<MonthValue>
     */
    private static function values(string $columnId, bool $known, array $months, bool $zeroWithoutMovement = true): array
    {
        $values = [];
        foreach ($months as $month) {
            $figures = $month->figures;
            if (null === $figures) {
                $values[] = new MonthValue($month->month, $month->state, null, null, $month->state->value);
                continue;
            }
            $metric = $known ? ($figures->cells[$columnId] ?? null) : null;
            if (null === $metric) {
                $values[] = new MonthValue($month->month, $month->state, null, null, $known ? self::NOT_IN_PROJECTION : self::UNKNOWN_REFERENCE, $figures->policyVersion);
                continue;
            }
            if ($zeroWithoutMovement && null === $metric->value && self::NO_MOVEMENTS === $metric->reason) {
                // A cell keeps the monthly report's null; aggregates count no movement as an exact zero.
                $values[] = new MonthValue($month->month, $month->state, DecimalValue::zero(), null, null, $figures->policyVersion);
                continue;
            }
            $values[] = new MonthValue(
                $month->month,
                $month->state,
                null === $metric->value ? null : DecimalValue::fromString($metric->value),
                null === $metric->assetCode ? null : AssetCode::fromString($metric->assetCode),
                $metric->reason,
                $figures->policyVersion,
            );
        }

        return $values;
    }
}
