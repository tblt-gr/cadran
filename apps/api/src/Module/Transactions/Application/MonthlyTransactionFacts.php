<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

final readonly class MonthlyTransactionFacts
{
    /**
     * @param list<MonthlyTransactionFact> $booked
     * @param list<MonthlyTransactionFact> $pending
     */
    public function __construct(
        public array $booked,
        public array $pending,
        public int $pendingCount,
    ) {
    }
}
