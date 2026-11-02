<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

final readonly class MonthlyTransactionFacts
{
    /**
     * @param list<MonthlyTransactionFact> $booked
     * @param list<MonthlyTransactionFact> $pending      every pending row, or only the unplanned ones when the planned rows were asked for
     * @param int                          $pendingCount the number of rows in $pending
     * @param list<MonthlyTransactionFact> $upcoming     the planned rows (pending, carrying the booking marker), only when asked for:
     *                                                   they are read as if booked on their date, without ever being booked
     */
    public function __construct(
        public array $booked,
        public array $pending,
        public int $pendingCount,
        public array $upcoming = [],
    ) {
    }

    /** @return list<MonthlyTransactionFact> the booked rows followed by the planned ones */
    public function bookedWithUpcoming(): array
    {
        return [] === $this->upcoming ? $this->booked : [...$this->booked, ...$this->upcoming];
    }
}
