<?php

declare(strict_types=1);

namespace App\Module\Reporting\UI\Http;

use App\Module\Accounts\Application\NetWorthAllocationView;
use App\Module\Accounts\Application\NetWorthAmountView;
use App\Module\Accounts\Application\ShareView;
use App\Module\Reporting\Application\MonthlyAccountValueView;
use App\Module\Reporting\Application\MonthlyMetricView;
use App\Module\Reporting\Application\MonthlyRecapAccountView;
use App\Module\Reporting\Application\MonthlyRecapAxisView;
use App\Module\Reporting\Application\MonthlyRecapCategoryView;
use App\Module\Reporting\Application\MonthlyRecapNetWorthView;
use App\Module\Reporting\Application\MonthlyRecapTotalsView;
use App\Module\Reporting\Application\MonthlyRecapView;
use App\Module\Transactions\Application\TransactionSummaryView;

final class MonthlyRecapRepresentation
{
    /** @return array<string, mixed> */
    public static function of(MonthlyRecapView $view): array
    {
        return [
            'month' => $view->month,
            'previousAsOf' => $view->previousAsOf,
            'currentAsOf' => $view->currentAsOf,
            'provisional' => $view->provisional,
            'state' => $view->state,
            'quality' => $view->quality,
            'accounts' => array_map(self::account(...), $view->accounts),
            'groups' => array_map(self::group(...), $view->groups),
            'netWorth' => self::netWorth($view->netWorth),
            'totals' => self::totals($view->totals),
        ];
    }

    /** @return array<string, mixed> */
    private static function account(MonthlyRecapAccountView $account): array
    {
        return [
            'accountId' => $account->accountId,
            'label' => $account->label,
            'kind' => $account->kind,
            'netWorthSign' => $account->netWorthSign,
            'primaryGroupId' => $account->primaryGroupId,
            'primaryGroupLabel' => $account->primaryGroupLabel,
            'eligible' => $account->eligible,
            'previousValue' => self::value($account->previousValue),
            'currentValue' => self::value($account->currentValue),
            'share' => self::share($account->share),
        ];
    }

    /** @return array<string, mixed>|null */
    private static function value(?MonthlyAccountValueView $value): ?array
    {
        return null === $value ? null : [
            'value' => $value->value,
            'assetCode' => $value->assetCode,
            'quality' => $value->quality,
            'ageDays' => $value->ageDays,
            'valuedOn' => $value->valuedOn,
        ];
    }

    /** @return array<string, mixed> */
    private static function group(NetWorthAllocationView $group): array
    {
        return [
            'groupId' => $group->groupId,
            'label' => $group->label,
            'parentId' => $group->parentId,
            'depth' => $group->depth,
            'value' => self::amount($group->value),
            'share' => self::share($group->share),
        ];
    }

    /** @return array<string, mixed> */
    private static function netWorth(MonthlyRecapNetWorthView $netWorth): array
    {
        return [
            'previous' => self::amount($netWorth->previous),
            'previousReason' => $netWorth->previousReason,
            'current' => self::amount($netWorth->current),
            'currentReason' => $netWorth->currentReason,
            'difference' => self::amount($netWorth->difference),
            'differenceReason' => $netWorth->differenceReason,
            'changeRatio' => $netWorth->changeRatio,
            'changePercent' => $netWorth->changePercent,
            'changePercentDisplay' => $netWorth->changePercentDisplay,
            'changeReason' => $netWorth->changeReason,
            'previousQuality' => $netWorth->previousQuality,
            'previousStalestAgeDays' => $netWorth->previousStalestAgeDays,
            'previousMissingValuationCount' => $netWorth->previousMissingValuationCount,
            'previousStaleValuationCount' => $netWorth->previousStaleValuationCount,
            'quality' => $netWorth->quality,
            'stalestAgeDays' => $netWorth->stalestAgeDays,
            'eligibleAccountCount' => $netWorth->eligibleAccountCount,
            'missingValuationCount' => $netWorth->missingValuationCount,
            'staleValuationCount' => $netWorth->staleValuationCount,
            'previousSourceAccountIds' => $netWorth->previousSourceAccountIds,
            'currentSourceAccountIds' => $netWorth->currentSourceAccountIds,
        ];
    }

    /** @return array<string, mixed> */
    private static function totals(MonthlyRecapTotalsView $totals): array
    {
        return [
            'cashIncome' => self::metric($totals->cashIncome, 'cashIncome'),
            'budgetExpenses' => self::metric($totals->budgetExpenses, 'budgetExpenses'),
            'savingsInflows' => self::metric($totals->savingsInflows, 'savingsInflows'),
            'savingsWithdrawals' => self::metric($totals->savingsWithdrawals, 'savingsWithdrawals'),
            'netSavingsTransfers' => self::metric($totals->netSavingsTransfers, 'netSavingsTransfers'),
            'netSavingsRate' => self::metric($totals->netSavingsRate, 'netSavingsRate'),
            'expensesByAxis' => array_map(self::axis(...), $totals->expensesByAxis),
            'categories' => array_map(self::category(...), $totals->categories),
        ];
    }

    /** @return array<string, mixed> */
    private static function category(MonthlyRecapCategoryView $category): array
    {
        return [
            'id' => $category->id,
            'label' => $category->label,
            'type' => $category->type,
            ...self::metric($category->metric, null),
            'sourceTransactions' => array_map(self::sourceTransaction(...), $category->sourceTransactions),
        ];
    }

    /** @return array<string, mixed> */
    private static function axis(MonthlyRecapAxisView $axis): array
    {
        return [
            'axis' => $axis->axis,
            ...self::metric($axis->metric, null),
            'sourceTransactions' => array_map(self::sourceTransaction(...), $axis->sourceTransactions),
        ];
    }

    /** @return array<string, mixed> */
    private static function metric(MonthlyMetricView $metric, ?string $kpi): array
    {
        return [
            'kpi' => $kpi,
            'value' => $metric->value,
            'assetCode' => $metric->assetCode,
            'reason' => $metric->reason,
            'pendingCount' => $metric->pendingCount,
            'sourceTransactionIds' => $metric->sourceTransactionIds,
            'sourceTransferIds' => $metric->sourceTransferIds,
        ];
    }

    /** @return array<string, mixed>|null */
    private static function amount(?NetWorthAmountView $amount): ?array
    {
        return null === $amount ? null : [
            'value' => $amount->amount,
            'assetCode' => $amount->asset,
            'display' => null === $amount->display ? null : [
                'value' => $amount->display,
                'assetCode' => $amount->asset,
            ],
            'belowDisplayStep' => $amount->belowDisplayStep,
        ];
    }

    /** @return array<string, mixed> */
    private static function sourceTransaction(TransactionSummaryView $transaction): array
    {
        return [
            'id' => $transaction->id,
            'bookedOn' => $transaction->bookedOn,
            'label' => $transaction->label,
            'amount' => ['value' => $transaction->amount, 'assetCode' => $transaction->assetCode],
            'state' => $transaction->state,
        ];
    }

    /** @return array<string, mixed> */
    private static function share(ShareView $share): array
    {
        return [
            'ratio' => $share->ratio,
            'percent' => $share->percent,
            'percentDisplay' => $share->percentDisplay,
            'reason' => $share->reason,
        ];
    }
}
