<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Recurrence;

/** The outcome of one materialisation run, shared by the HTTP endpoint and the console command. */
final readonly class MaterializeRecurrencesResult
{
    public function __construct(
        public int $recurrencesExamined,
        public int $transactionsCreated,
        public int $transactionsBooked,
        public int $skippedClosedPeriod,
        public int $skippedRefused,
        public string $monthStart,
        public string $monthEnd,
    ) {
    }

    /** @return array{recurrencesExamined: int, transactionsCreated: int, transactionsBooked: int, skippedClosedPeriod: int, skippedRefused: int, monthStart: string, monthEnd: string} */
    public function toArray(): array
    {
        return [
            'recurrencesExamined' => $this->recurrencesExamined,
            'transactionsCreated' => $this->transactionsCreated,
            'transactionsBooked' => $this->transactionsBooked,
            'skippedClosedPeriod' => $this->skippedClosedPeriod,
            'skippedRefused' => $this->skippedRefused,
            'monthStart' => $this->monthStart,
            'monthEnd' => $this->monthEnd,
        ];
    }
}
