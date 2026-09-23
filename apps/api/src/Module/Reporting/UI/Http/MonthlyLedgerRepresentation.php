<?php

declare(strict_types=1);

namespace App\Module\Reporting\UI\Http;

use App\Module\Reporting\Application\MonthlyLedgerAccountRowView;
use App\Module\Reporting\Application\MonthlyLedgerCategoryRowView;
use App\Module\Reporting\Application\MonthlyLedgerMovementPageView;
use App\Module\Reporting\Application\MonthlyLedgerMovementView;
use App\Module\Reporting\Application\MonthlyLedgerView;
use App\Module\Reporting\Application\MonthlyMetricView;

final class MonthlyLedgerRepresentation
{
    /** @return array<string, mixed> */
    public static function summary(MonthlyLedgerView $view): array
    {
        return [
            'month' => $view->month,
            'periodStart' => $view->periodStart,
            'periodEnd' => $view->periodEnd,
            'axis' => $view->axis,
            'state' => $view->state,
            'quality' => $view->quality,
            'timezone' => $view->timezone,
            'firstDataMonth' => $view->firstDataMonth,
            'pendingCount' => $view->pendingCount,
            'closed' => $view->closed,
            'actionsAllowed' => $view->actionsAllowed,
            'actionReason' => $view->actionReason,
            'incomeCategories' => array_map(self::category(...), $view->incomeCategories),
            'expenseCategories' => array_map(self::category(...), $view->expenseCategories),
            'accounts' => array_map(self::account(...), $view->accounts),
        ];
    }

    /** @return array<string, mixed> */
    public static function page(MonthlyLedgerMovementPageView $page): array
    {
        return [
            'items' => array_map(self::movement(...), $page->items),
            'nextCursor' => $page->nextCursor,
            'hasMore' => $page->hasMore,
            'pageSize' => $page->pageSize,
        ];
    }

    /** @return array<string, mixed> */
    private static function category(MonthlyLedgerCategoryRowView $row): array
    {
        return [
            'id' => $row->id,
            'label' => $row->label,
            'icon' => $row->icon,
            'color' => $row->color,
            'budgetIncluded' => $row->budgetIncluded,
            'archived' => $row->archived,
            'total' => self::metric($row->total),
            'movementCount' => $row->movementCount,
            'hasMovements' => $row->hasMovements,
        ];
    }

    /** @return array<string, mixed> */
    private static function account(MonthlyLedgerAccountRowView $row): array
    {
        return [
            'id' => $row->id,
            'label' => $row->label,
            'assetCode' => $row->assetCode,
            'kind' => $row->kind,
            'total' => self::metric($row->total),
            'movementCount' => $row->movementCount,
            'hasMovements' => $row->hasMovements,
        ];
    }

    /** @return array<string, mixed> */
    private static function movement(MonthlyLedgerMovementView $movement): array
    {
        return [
            'id' => $movement->id,
            'transactionId' => $movement->transactionId,
            'transferId' => $movement->transferId,
            'bookedOn' => $movement->bookedOn,
            'label' => $movement->label,
            'amount' => ['value' => $movement->amount, 'assetCode' => $movement->assetCode],
            'direction' => $movement->direction,
            'counterpartAccountId' => $movement->counterpartAccountId,
            'counterpartAccountLabel' => $movement->counterpartAccountLabel,
        ];
    }

    /** @return array{value: ?string, assetCode: ?string, reason: ?string} */
    private static function metric(MonthlyMetricView $metric): array
    {
        return ['value' => $metric->value, 'assetCode' => $metric->assetCode, 'reason' => $metric->reason];
    }
}
