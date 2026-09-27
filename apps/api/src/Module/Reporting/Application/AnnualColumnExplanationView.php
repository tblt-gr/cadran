<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

final readonly class AnnualColumnExplanationView
{
    /** @param list<AnnualMonthExplanationView> $months */
    public function __construct(
        public string $column,
        public string $kind,
        public string $formula,
        public AnnualPolicyView $policy,
        public array $months,
        public AnnualAggregateView $aggregate,
    ) {
    }
}
