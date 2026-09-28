<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Foundation\Domain\DecimalValue;

final readonly class ReferenceIncomeView
{
    /** @param list<array{month: string, reason: string}> $excluded */
    public function __construct(
        public string $month,
        public ?DecimalValue $value,
        public int $usableMonths,
        public string $windowStart,
        public string $windowEnd,
        public ?string $reason,
        public array $excluded,
    ) {
    }
}
