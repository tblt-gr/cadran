<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

/** The `metricPolicy` member of a KPI response; the version is null when a period mixes several. */
final readonly class MetricPolicyReference
{
    public const string UNKNOWN_LABEL = 'Politique inconnue';

    public function __construct(
        public ?int $version,
        public ?string $label,
    ) {
    }
}
