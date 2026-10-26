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
    ) {
    }
}
