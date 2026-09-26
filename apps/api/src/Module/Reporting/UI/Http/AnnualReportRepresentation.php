<?php

declare(strict_types=1);

namespace App\Module\Reporting\UI\Http;

use App\Module\Reporting\Application\AnnualAggregateView;
use App\Module\Reporting\Application\AnnualCategoryShareView;
use App\Module\Reporting\Application\AnnualCellView;
use App\Module\Reporting\Application\AnnualColumnExplanationView;
use App\Module\Reporting\Application\AnnualExtremeView;
use App\Module\Reporting\Application\AnnualPolicyView;
use App\Module\Reporting\Application\AnnualReportView;

final class AnnualReportRepresentation
{
    /** @return array<string, mixed> */
    public static function of(AnnualReportView $report): array
    {
        return [
            'year' => $report->year,
            'today' => $report->today,
            'incompleteMonths' => $report->incompleteMonths,
            'metricPolicy' => self::policy($report->metricPolicy),
            'columns' => array_map(static fn ($column): array => [
                'id' => $column->id,
                'label' => $column->label,
                'kind' => $column->kind,
                'assetCode' => $column->assetCode,
            ], $report->columns),
            'rows' => array_map(static fn ($row): array => [
                'month' => $row->month,
                'state' => $row->state,
                'closed' => $row->closed,
                'snapshot' => $row->snapshot,
                'policyVersion' => $row->policyVersion,
                'pendingCount' => $row->pendingCount,
                'cells' => (object) array_map(static fn ($cell): array => ['value' => $cell->value, 'reason' => $cell->reason], $row->cells),
            ], $report->rows),
            'aggregates' => (object) array_map(self::aggregate(...), $report->aggregates),
            'charts' => [
                'topExpenseCategories' => [
                    'items' => array_map(self::share(...), $report->topExpenseCategories->items),
                    'other' => null === $report->topExpenseCategories->other ? null : self::share($report->topExpenseCategories->other),
                    'reason' => $report->topExpenseCategories->reason,
                    'assetCode' => $report->topExpenseCategories->assetCode,
                ],
                'allocation' => [
                    'asOf' => $report->allocation->asOf,
                    'assetCode' => $report->allocation->assetCode,
                    'items' => array_map(static fn ($item): array => [
                        'groupId' => $item->groupId,
                        'label' => $item->label,
                        'value' => $item->value,
                        'share' => $item->share,
                        'reason' => $item->reason,
                    ], $report->allocation->items),
                    'reason' => $report->allocation->reason,
                ],
                'flows' => [
                    'assetCode' => $report->flows->assetCode,
                    'months' => array_map(static fn ($point): array => [
                        'month' => $point->month,
                        'cashIncome' => self::cell($point->cashIncome),
                        'budgetExpenses' => self::cell($point->budgetExpenses),
                        'budgetSurplus' => self::cell($point->budgetSurplus),
                    ], $report->flows->months),
                ],
                'netWorth' => [
                    'assetCode' => $report->netWorth->assetCode,
                    'months' => array_map(static fn ($point): array => ['month' => $point->month, 'value' => self::cell($point->value)], $report->netWorth->months),
                ],
            ],
            'quality' => $report->quality,
            'previousYearHasData' => $report->previousYearHasData,
        ];
    }

    /** @return array<string, mixed> */
    public static function explanation(AnnualColumnExplanationView $explanation): array
    {
        return [
            'column' => $explanation->column,
            'kind' => $explanation->kind,
            'formula' => $explanation->formula,
            'policy' => self::policy($explanation->policy),
            'months' => array_map(static fn ($month): array => [
                'month' => $month->month,
                'state' => $month->state,
                'value' => $month->value,
                'reason' => $month->reason,
                'counted' => $month->counted,
                'exclusionReason' => $month->exclusionReason,
            ], $explanation->months),
            'aggregate' => self::aggregate($explanation->aggregate),
        ];
    }

    /** @return array<string, mixed> */
    private static function cell(AnnualCellView $cell): array
    {
        return ['value' => $cell->value, 'reason' => $cell->reason];
    }

    /** @return array<string, mixed> */
    private static function policy(AnnualPolicyView $policy): array
    {
        return ['state' => $policy->state, 'version' => $policy->version, 'label' => $policy->label, 'versions' => $policy->versions];
    }

    /** @return array<string, mixed> */
    private static function aggregate(AnnualAggregateView $aggregate): array
    {
        $extreme = static fn (?AnnualExtremeView $extreme): ?array => null === $extreme ? null : ['value' => $extreme->value, 'month' => $extreme->month];

        return [
            'kind' => $aggregate->kind,
            'total' => $aggregate->total,
            'totalReason' => $aggregate->totalReason,
            'average' => $aggregate->average,
            'averageReason' => $aggregate->averageReason,
            'median' => $aggregate->median,
            'medianReason' => $aggregate->medianReason,
            'minimum' => $extreme($aggregate->minimum),
            'maximum' => $extreme($aggregate->maximum),
            'extremesReason' => $aggregate->extremesReason,
            'periodEnd' => $extreme($aggregate->periodEnd),
            'previousYearAverage' => $aggregate->previousYearAverage,
            'previousYearAverageReason' => $aggregate->previousYearAverageReason,
            'countedMonths' => $aggregate->countedMonths,
            'excludedMonths' => $aggregate->excludedMonths,
            'quality' => $aggregate->quality,
            'formula' => $aggregate->formula,
        ];
    }

    /** @return array<string, mixed> */
    private static function share(AnnualCategoryShareView $share): array
    {
        $item = ['categoryId' => $share->categoryId, 'label' => $share->label, 'total' => $share->total, 'share' => $share->share];
        if (null === $share->categoryId) {
            unset($item['categoryId'], $item['label']);
        }

        return $item;
    }
}
