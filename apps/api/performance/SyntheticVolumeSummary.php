<?php

declare(strict_types=1);

namespace App\Tests\Performance;

final readonly class SyntheticVolumeSummary
{
    /** @param array<string, int> $rowsPerMonth live (booked or pending) transactions of each month, by `YYYY-MM` */
    public function __construct(
        public int $transactions,
        public int $months,
        public string $firstMonth,
        public string $lastMonth,
        public int $transferPairs,
        /** Transactions of the busiest month: the figure the monthly read bound applies to. */
        public int $busiestMonth,
        /** The month deliberately made much heavier than the others; empty when the history is too short. */
        public string $heavyMonth,
        public array $rowsPerMonth,
    ) {
    }
}
