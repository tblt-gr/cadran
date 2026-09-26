<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

final readonly class MetricPolicyCatalogView
{
    /** @param list<MetricPolicyView> $versions */
    public function __construct(
        public int $activeVersion,
        public ?MetricPolicyView $active,
        public ?string $activeSince,
        public array $versions,
    ) {
    }
}
