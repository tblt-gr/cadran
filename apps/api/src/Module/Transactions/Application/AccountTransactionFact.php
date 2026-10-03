<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Foundation\Domain\AssetAmount;

/** What another module may know about one transaction of an account: no label, no counterparty, no category. */
final readonly class AccountTransactionFact
{
    public function __construct(
        public string $id,
        public string $nature,
        public AssetAmount $amount,
        public \DateTimeImmutable $bookedOn,
        public string $state,
        public bool $voided,
    ) {
    }
}
