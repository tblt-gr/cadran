<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Plan;

use App\Module\Foundation\Domain\DecimalValue;

/** An atomic internal transfer; `amount` is a positive magnitude leaving `sourceAccountId`. */
final readonly class PlannedTransfer implements PlannedStep
{
    public function __construct(
        private int $month,
        private string $sheet,
        private string $cell,
        public string $key,
        public string $sourceAccountId,
        public string $targetAccountId,
        public DecimalValue $amount,
        public string $bookedOn,
        public string $label,
        public string $note,
    ) {
    }

    public function kind(): StepKind
    {
        return StepKind::TRANSFER;
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
