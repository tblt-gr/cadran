<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Accounts\Application\NetWorthAllocationView;

final readonly class MonthlyRecapView
{
    /**
     * @param list<MonthlyRecapAccountView> $accounts
     * @param list<NetWorthAllocationView>  $groups   one exclusive subtotal per primary
     *                                                group; a secondary label never adds a
     *                                                second total for the same account
     */
    public function __construct(
        public string $month,
        public string $previousAsOf,
        public string $currentAsOf,
        public bool $provisional,
        public string $state,
        public string $quality,
        public array $accounts,
        public array $groups,
        public MonthlyRecapNetWorthView $netWorth,
        public MonthlyRecapTotalsView $totals,
        public MetricPolicyReference $metricPolicy,
    ) {
    }
}
