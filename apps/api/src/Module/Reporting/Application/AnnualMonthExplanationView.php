<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

final readonly class AnnualMonthExplanationView
{
    public function __construct(
        public string $month,
        public string $state,
        public ?string $value,
        public ?string $reason,
        public bool $counted,
        public ?string $exclusionReason,
    ) {
    }
}
