<?php

declare(strict_types=1);

namespace App\Module\Reporting\UI\Http;

use App\Module\Reporting\Application\MultiYearCellView;
use App\Module\Reporting\Application\MultiYearDetailedAggregateView;
use App\Module\Reporting\Application\MultiYearDetailedExtremeView;
use App\Module\Reporting\Application\MultiYearExtremeView;
use App\Module\Reporting\Application\MultiYearMetricPolicyView;
use App\Module\Reporting\Application\MultiYearReportView;
use App\Module\Reporting\Application\MultiYearSummaryCellView;
use App\Module\Reporting\Application\MultiYearYearView;

final class MultiYearReportRepresentation
{
    /** @return array<string, mixed> */
    public static function of(MultiYearReportView $report): array
    {
        return [
            'from' => $report->from,
            'to' => $report->to,
            'availableFrom' => $report->availableFrom,
            'inflationAdjusted' => $report->inflationAdjusted,
            'incompleteMonths' => $report->incompleteMonths,
            'columns' => array_map(static fn ($column): array => [
                'id' => $column->id,
                'label' => $column->label,
                'kind' => $column->kind,
                'assetCode' => $column->assetCode,
                'institution' => $column->institution,
            ], $report->columns),
            'years' => array_map(self::year(...), $report->years),
            'summary' => (object) array_map(self::summaryCell(...), $report->summary),
            'quality' => $report->quality,
            'detailedPeriod' => (object) array_map(self::detailedAggregate(...), $report->detailedPeriod),
        ];
    }

    /** @return array<string, mixed> */
    private static function year(MultiYearYearView $year): array
    {
        return [
            'year' => $year->year,
            'state' => $year->state,
            'countedMonths' => $year->countedMonths,
            'snapshotMonths' => $year->snapshotMonths,
            'liveMonths' => $year->liveMonths,
            'metricPolicy' => self::policy($year->metricPolicy),
            'cells' => (object) array_map(self::cell(...), $year->cells),
        ];
    }

    /** @return array<string, mixed> */
    private static function policy(MultiYearMetricPolicyView $policy): array
    {
        return ['state' => $policy->state, 'version' => $policy->version];
    }

    /** @return array<string, mixed> */
    private static function cell(MultiYearCellView $cell): array
    {
        return [
            'value' => $cell->value,
            'reason' => $cell->reason,
            'asOf' => $cell->asOf,
            'change' => $cell->change,
            'changeRatio' => $cell->changeRatio,
            'changeReason' => $cell->changeReason,
        ];
    }

    /** @return array<string, mixed> */
    private static function summaryCell(MultiYearSummaryCellView $cell): array
    {
        $extreme = static fn (?MultiYearExtremeView $extreme): ?array => null === $extreme ? null : ['value' => $extreme->value, 'year' => $extreme->year];

        return [
            'average' => $cell->average,
            'averageReason' => $cell->averageReason,
            'minimum' => $extreme($cell->minimum),
            'maximum' => $extreme($cell->maximum),
        ];
    }

    /** @return array<string, mixed> */
    private static function detailedAggregate(MultiYearDetailedAggregateView $aggregate): array
    {
        $extreme = static fn (?MultiYearDetailedExtremeView $extreme): ?array => null === $extreme ? null : ['value' => $extreme->value, 'month' => $extreme->month];

        return [
            'total' => $aggregate->total,
            'totalReason' => $aggregate->totalReason,
            'average' => $aggregate->average,
            'averageReason' => $aggregate->averageReason,
            'median' => $aggregate->median,
            'medianReason' => $aggregate->medianReason,
            'minimum' => $extreme($aggregate->minimum),
            'maximum' => $extreme($aggregate->maximum),
            'extremesReason' => $aggregate->extremesReason,
            'quality' => $aggregate->quality,
        ];
    }
}
