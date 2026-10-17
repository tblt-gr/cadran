<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Plan;

use App\Module\Foundation\Domain\DecimalValue;

/** A `TOTAL` portfolio valuation: securities value plus cash, the cash being observed. */
final readonly class PlannedValuation implements PlannedStep
{
    public function __construct(
        private int $month,
        private string $sheet,
        private string $cell,
        public string $accountId,
        public DecimalValue $total,
        public DecimalValue $cash,
        public string $valuedOn,
        public string $note,
    ) {
    }

    public function kind(): StepKind
    {
        return StepKind::VALUATION;
    }

    public function month(): int
    {
        return $this->month;
    }

    public function sheet(): string
    {
        return $this->sheet;
    }

    public function cell(): string
    {
        return $this->cell;
    }
}
