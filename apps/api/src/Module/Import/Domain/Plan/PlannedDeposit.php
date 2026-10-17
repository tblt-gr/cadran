<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Plan;

use App\Module\Foundation\Domain\DecimalValue;

/**
 * A portfolio deposit. It is linked to the incoming leg of the savings
 * transfer named by `linkedTransferKey`, or stands alone for the opening state.
 */
final readonly class PlannedDeposit implements PlannedStep
{
    public function __construct(
        private int $month,
        private string $sheet,
        private ?string $cell,
        public string $accountId,
        public DecimalValue $amount,
        public string $bookedOn,
        public ?string $linkedTransferKey,
        public string $reference,
    ) {
    }

    public function kind(): StepKind
    {
        return StepKind::DEPOSIT;
    }

    public function month(): int
    {
        return $this->month;
    }

    public function sheet(): string
    {
        return $this->sheet;
    }

    public function cell(): ?string
    {
        return $this->cell;
    }
}
