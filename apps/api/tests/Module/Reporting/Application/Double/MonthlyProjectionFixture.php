<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\Application\Double;

use App\Module\Accounts\Application\NetWorthDeltaView;
use App\Module\Accounts\Application\NetWorthView;
use App\Module\Reporting\Application\MetricPolicyReference;
use App\Module\Reporting\Application\MonthlyMetricView;
use App\Module\Reporting\Application\MonthlyProjectionView;

final class MonthlyProjectionFixture
{
    public static function view(string $month, string $cashIncome = '2000', int $policyVersion = 1): MonthlyProjectionView
    {
        $income = new MonthlyMetricView($cashIncome, 'EUR', null);
        $zero = new MonthlyMetricView('0', 'EUR', null);
        $none = new MonthlyMetricView(null, null, 'NO_VALUE');

        return new MonthlyProjectionView(
            $month, $month.'-01', $month.'-28', $month.'-01', $month.'-28', false, 'COMPLETE', 'CURRENT', 0,
            $income, $zero, $zero, $zero, $zero, $income, $zero, $none, $zero, $zero, $zero, $none,
            $none, $none, $none, 'NON_CALCULABLE', [], 'RECONCILED', [], [],
            new NetWorthView(
                $month.'-28', null, 'MISSING_VALUATION', 'MISSING', null, 0, 0, 0, 0,
                new NetWorthDeltaView($month.'-01', null, null, null, null, null, null, null, null, 'MISSING', null, 0, 0, [], []),
                [], [],
            ),
            new MetricPolicyReference($policyVersion, 'Définition de trésorerie'),
        );
    }
}
