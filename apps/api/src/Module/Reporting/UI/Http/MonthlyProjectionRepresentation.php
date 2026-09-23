<?php

declare(strict_types=1);

namespace App\Module\Reporting\UI\Http;

use App\Module\Reporting\Application\MonthlyAccountValueView;
use App\Module\Reporting\Application\MonthlyAccountView;
use App\Module\Reporting\Application\MonthlyMetricView;
use App\Module\Reporting\Application\MonthlyProjectionView;

final class MonthlyProjectionRepresentation
{
    /** @return array<string, mixed> */
    public static function of(MonthlyProjectionView $view): array
    {
        return [
            'month' => $view->month,
            'periodStart' => $view->periodStart,
            'periodEnd' => $view->periodEnd,
            'state' => $view->state,
            'quality' => $view->quality,
            'pendingCount' => $view->pendingCount,
            'cashIncome' => self::metric($view->cashIncome),
            'nonCashBenefits' => self::metric($view->nonCashBenefits),
            'budgetExpenses' => self::metric($view->budgetExpenses),
            'uncategorizedExpenses' => self::metric($view->uncategorizedExpenses),
            'budgetSurplus' => self::metric($view->budgetSurplus),
            'savingsTransfers' => self::metric($view->savingsTransfers),
            'cashSavingsRate' => self::metric($view->cashSavingsRate),
            'savingsInflows' => self::metric($view->savingsInflows),
            'savingsWithdrawals' => self::metric($view->savingsWithdrawals),
            'netSavingsTransfers' => self::metric($view->netSavingsTransfers),
            'netSavingsRate' => self::metric($view->netSavingsRate),
            'beginningNetWorth' => self::metric($view->beginningNetWorth),
            'endNetWorth' => self::metric($view->endNetWorth),
            'netWorthDelta' => self::metric($view->netWorthDelta),
            'beginningNetWorthState' => $view->beginningNetWorthState,
            'accounts' => array_map(self::account(...), $view->accounts),
            'reconciliationStatus' => $view->reconciliationStatus,
        ];
    }

    /** @return array{value: ?string, assetCode: ?string, reason: ?string} */
    private static function metric(MonthlyMetricView $metric): array
    {
        return ['value' => $metric->value, 'assetCode' => $metric->assetCode, 'reason' => $metric->reason];
    }

    /** @return array<string, mixed> */
    private static function account(MonthlyAccountView $account): array
    {
        return [
            'id' => $account->id,
            'label' => $account->label,
            'assetCode' => $account->assetCode,
            'kind' => $account->kind,
            'beginningValue' => self::accountValue($account->beginningValue),
            'endValue' => self::accountValue($account->endValue),
            'reconciliationStatus' => $account->reconciliationStatus,
        ];
    }

    /** @return array<string, mixed> */
    private static function accountValue(MonthlyAccountValueView $value): array
    {
        return [
            'value' => $value->value,
            'assetCode' => $value->assetCode,
            'quality' => $value->quality,
            'ageDays' => $value->ageDays,
            'valuedOn' => $value->valuedOn,
        ];
    }
}
