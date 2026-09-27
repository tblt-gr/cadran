<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

/** The metric policy of one multi-year row: its state (`SINGLE`/`MIXED`) and, when single, its version. */
final readonly class MultiYearMetricPolicyView
{
    public function __construct(public string $state, public ?int $version)
    {
    }

    public static function of(AnnualPolicyView $policy): self
    {
        return new self($policy->state, $policy->version);
    }
}
