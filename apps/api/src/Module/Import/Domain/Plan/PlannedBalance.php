<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Plan;

use App\Module\Foundation\Domain\DecimalValue;

/** A dated balance snapshot read from the account table. */
final readonly class PlannedBalance implements PlannedStep
{
    public function __construct(
        private int $month,
        private string $sheet,
        private string $cell,
        public string $accountId,
        public DecimalValue $amount,
        public string $asOf,
        public string $comment,
    ) {
    }

    public function kind(): StepKind
    {
        return StepKind::BALANCE;
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
