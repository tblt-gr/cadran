<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

final readonly class MonthlyBudgetActualsView
{
    /** @param array<string, MonthlyBudgetActualView> $actuals keyed by scope key */
    public function __construct(
        public MonthlyMetricView $cashIncome,
        public array $actuals,
        public MetricPolicyReference $metricPolicy,
    ) {
    }
}
