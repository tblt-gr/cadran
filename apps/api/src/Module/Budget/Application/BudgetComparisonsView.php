<?php

declare(strict_types=1);

namespace App\Module\Budget\Application;

use App\Module\Reporting\Application\MetricPolicyReference;

final readonly class BudgetComparisonsView
{
    /** @param list<BudgetComparisonView> $comparisons */
    public function __construct(
        public string $planId,
        public string $period,
        public string $assetCode,
        public string $status,
        public ?string $reason,
        public array $comparisons,
        public MetricPolicyReference $metricPolicy,
    ) {
    }
}
