<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Plan;

use App\Module\Foundation\Domain\DecimalValue;

/** A refund of a planned expense of the same month; `amount` is a positive magnitude. */
final readonly class PlannedRefund implements PlannedStep
{
    public function __construct(
        private int $month,
        private string $sheet,
        private string $cell,
        public string $accountId,
        public string $originalKey,
        public DecimalValue $amount,
        public string $bookedOn,
        public string $rawLabel,
        public string $note,
    ) {
    }

    public function kind(): StepKind
    {
        return StepKind::REFUND;
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
