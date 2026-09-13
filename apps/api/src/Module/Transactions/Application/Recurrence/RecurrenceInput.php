<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Recurrence;

/**
 * A confirmation, whether it accepts a detected candidate or states a schedule
 * by hand. Amounts travel as submitted literals: parsing them into a decimal is
 * the asset's job, so a figure the asset cannot store is refused rather than
 * shortened.
 */
final readonly class RecurrenceInput
{
    public function __construct(
        public string $accountId,
        public string $label,
        public ?string $counterparty,
        public string $expectedAmount,
        public string $amountTolerance,
        public string $intervalKind,
        public int $dayOfPeriod,
        public string $firstExpectedOn,
    ) {
    }
}
