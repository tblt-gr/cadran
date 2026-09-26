<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain;

final readonly class MetricPolicyActivation
{
    public function __construct(
        public int $policyVersion,
        public \DateTimeImmutable $activeFrom,
        public ?string $reason = null,
        public ?string $createdBy = null,
    ) {
    }
}
