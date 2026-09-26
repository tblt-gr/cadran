<?php

declare(strict_types=1);

namespace App\Module\Budget\Application;

use App\Module\Reporting\Application\MetricPolicyReference;

final readonly class BudgetPlanDetailView
{
    /** @param list<BudgetTargetDetailView> $targets */
    public function __construct(
        public string $id,
        public string $periodType,
        public string $period,
        public string $assetCode,
        public string $state,
        public int $version,
        public array $targets,
        public MetricPolicyReference $metricPolicy,
    ) {
    }
}
