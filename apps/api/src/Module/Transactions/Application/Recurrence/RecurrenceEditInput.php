<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Recurrence;

/**
 * What an edit may change. The account and the date the schedule started are
 * absent on purpose: moving either would rewrite instalments already explained
 * to the user, and a recurrence change is forward-only.
 */
final readonly class RecurrenceEditInput
{
    public function __construct(
        public string $label,
        public ?string $counterparty,
        public string $expectedAmount,
        public string $amountTolerance,
        public string $intervalKind,
        public int $dayOfPeriod,
        public int $version,
        public bool $autoCreate = false,
        public ?string $categoryId = null,
        public ?string $note = null,
        public ?string $paymentMethod = null,
        /** A later period start: instalments before it are dropped. Null, or a date up to today, changes nothing. */
        public ?string $firstExpectedOn = null,
    ) {
    }
}
