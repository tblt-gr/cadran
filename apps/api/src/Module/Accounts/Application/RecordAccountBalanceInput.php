<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

final readonly class RecordAccountBalanceInput
{
    public function __construct(
        public string $asOf,
        public string $amount,
        public string $amountAssetCode,
        public ?string $comment,
        public ?int $version,
    ) {
    }
}
