<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

/** The `metricPolicy` member of a KPI response; the version is null when a period mixes several. */
final readonly class MetricPolicyReference
{
    public function __construct(
        public ?int $version,
        public ?string $label,
    ) {
    }

    /** False when the definition of the version cannot be loaded. */
    public function definitionKnown(): bool
    {
        return null !== $this->label;
    }
}
