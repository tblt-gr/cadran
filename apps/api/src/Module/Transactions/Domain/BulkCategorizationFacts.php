<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain;

/** What the surrounding data says about one transaction, read once per bulk run. */
final readonly class BulkCategorizationFacts
{
    public function __construct(
        public bool $transferLeg = false,
        public bool $refund = false,
        public bool $liveRefund = false,
        public bool $periodClosed = false,
        public bool $portfolioLinked = false,
        public bool $reconciled = false,
        /** Whether the month a date change would move the row into is closed. */
        public bool $targetPeriodClosed = false,
        /** The booked day of the original when the row is a refund, to keep a refund from predating it. */
        public ?\DateTimeImmutable $originalBookedOn = null,
    ) {
    }
}
