<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

final readonly class CreateMetricPolicyInput
{
    /** @param list<string> $cashExcludedAccountKinds */
    public function __construct(
        public string $label,
        public array $cashExcludedAccountKinds,
    ) {
    }
}
