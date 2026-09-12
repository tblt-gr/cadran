<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

/**
 * The amounts, accounts and asset composition of a transfer are set once at
 * creation, exactly like the asset and account of a plain transaction: an
 * edit corrects the booking (state, dates, label, note, fee), never the
 * money it moved. Correcting an amount means voiding the transfer and
 * recording a new one.
 */
final readonly class UpdateTransferInput
{
    public function __construct(
        public string $sourceAccountId,
        public string $targetAccountId,
        public string $state,
        public string $bookedOn,
        public ?string $valueOn,
        public string $label,
        public ?string $note,
        public mixed $fee,
        public int $version,
    ) {
    }
}
