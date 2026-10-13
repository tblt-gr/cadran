<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Foundation\Domain\AssetAmount;

/**
 * What a period total needs of one booked transaction: no label, counterparty, note, split or category, so a
 * long period is read as a few scalars per row instead of a hydrated transaction.
 */
final readonly class BookedMovementFact
{
    public function __construct(
        public string $id,
        public string $accountId,
        public string $nature,
        public AssetAmount $amount,
    ) {
    }
}
