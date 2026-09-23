<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;

/** One persisted transfer pair as seen by the monthly reporting policy. */
final readonly class MonthlySavingsTransfer
{
    public function __construct(
        public string $transferId,
        public ?string $sourceTransactionId,
        public ?string $targetTransactionId,
        public ?string $sourceAccountId,
        public ?string $targetAccountId,
        public ?DecimalValue $sourceAmount,
        public ?DecimalValue $targetAmount,
        public ?AssetCode $sourceAsset,
        public ?AssetCode $targetAsset,
        public ?string $sourceState,
        public ?string $targetState,
        public ?string $sourceBookedOn,
        public ?string $targetBookedOn,
        public bool $voided,
    ) {
    }
}
