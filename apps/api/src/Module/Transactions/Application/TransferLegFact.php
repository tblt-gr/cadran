<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Foundation\Domain\AssetAmount;

/** What another module may know about one transfer leg: where it sits, its signed amount and whether it still counts. */
final readonly class TransferLegFact
{
    public function __construct(
        public string $id,
        public string $accountId,
        public AssetAmount $amount,
        public \DateTimeImmutable $bookedOn,
        public string $state,
        public bool $voided,
        public ?string $label,
    ) {
    }
}
