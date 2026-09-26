<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Catalog\Domain\AccountKind;
use App\Module\Reporting\Domain\MetricPolicy;

final readonly class MetricPolicyView
{
    /** @param list<string> $cashExcludedAccountKinds */
    public function __construct(
        public int $version,
        public string $label,
        public array $cashExcludedAccountKinds,
        public string $savingsRateFormula,
        public string $netSavingsRateFormula,
        public ?string $createdAt,
        public bool $system,
    ) {
    }

    public static function of(MetricPolicy $policy): self
    {
        return new self(
            $policy->version,
            $policy->label,
            array_map(static fn (AccountKind $kind): string => $kind->value, $policy->cashExcludedAccountKinds),
            $policy->savingsRateFormula->value,
            $policy->netSavingsRateFormula->value,
            $policy->createdAt?->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
            MetricPolicy::SYSTEM_VERSION === $policy->version,
        );
    }
}
