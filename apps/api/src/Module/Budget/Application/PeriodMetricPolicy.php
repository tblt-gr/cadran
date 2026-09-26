<?php

declare(strict_types=1);

namespace App\Module\Budget\Application;

use App\Module\Reporting\Application\MetricPolicyReference;
use App\Module\Reporting\Domain\MetricPolicy;

/**
 * The metric policy of a budget period: the policy of its month, or for a year
 * the policy shared by its twelve months. Months under different versions are
 * mixed and carry no single definition.
 */
final readonly class PeriodMetricPolicy
{
    public function __construct(
        public MetricPolicyReference $reference,
        public ?MetricPolicy $policy,
        public bool $mixed,
    ) {
    }
}
