<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Plan;

use App\Module\Foundation\Domain\DecimalValue;

/** A portfolio purchase of the instrument the mapping names, `gross` being exactly quantity x unit price. */
final readonly class PlannedBuy implements PlannedStep
{
    public function __construct(
        private int $month,
        private string $sheet,
        private string $cell,
        public string $accountId,
        public string $instrument,
        public DecimalValue $quantity,
        public DecimalValue $unitPrice,
        public DecimalValue $gross,
        public string $bookedOn,
        public string $reference,
        public ?string $note,
    ) {
    }

    public function kind(): StepKind
    {
        return StepKind::BUY;
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
