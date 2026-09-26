<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

final readonly class ActivateMetricPolicyInput
{
    public function __construct(
        public int $version,
        public int $expectedActiveVersion,
        public ?string $reason = null,
    ) {
    }
}
