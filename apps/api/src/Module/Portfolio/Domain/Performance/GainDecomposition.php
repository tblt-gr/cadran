<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Performance;

/** The total gain split into its causes; every component is an exact canonical amount or null together. */
final readonly class GainDecomposition
{
    public function __construct(
        public DecompositionStatus $status,
        public ?PerformanceReason $reason,
        public ?string $realized = null,
        public ?string $unrealizedChange = null,
        public ?string $income = null,
        public ?string $fees = null,
        public ?string $taxes = null,
        public ?string $corporateActionCash = null,
        public ?string $capitalizedCosts = null,
        public ?string $residual = null,
    ) {
    }

    public static function unavailable(?PerformanceReason $reason): self
    {
        return new self(DecompositionStatus::UNAVAILABLE, $reason);
    }
}
