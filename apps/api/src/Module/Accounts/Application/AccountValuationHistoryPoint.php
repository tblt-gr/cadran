<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

final readonly class AccountValuationHistoryPoint
{
    public function __construct(
        public string $date,
        public ?string $amount,
        public ?string $assetCode,
        public string $quality,
        public ?string $source,
        public ?string $reason,
        public ?string $asOf,
        public ?int $ageDays,
        public ?string $basis,
        public ?ValuationDerivationView $derivation,
    ) {
    }
}
