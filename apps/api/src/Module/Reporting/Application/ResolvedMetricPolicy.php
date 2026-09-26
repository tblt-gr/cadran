<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Reporting\Domain\MetricPolicy;

/** The version governing a month and its definition; the definition is null when it cannot be loaded. */
final readonly class ResolvedMetricPolicy
{
    public function __construct(
        public int $version,
        public ?MetricPolicy $policy,
    ) {
    }

    public function reference(): MetricPolicyReference
    {
        return new MetricPolicyReference($this->version, $this->policy->label ?? MetricPolicyReference::UNKNOWN_LABEL);
    }
}
