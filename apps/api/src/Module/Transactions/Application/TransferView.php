<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

final readonly class TransferView
{
    public function __construct(
        public string $id,
        public TransactionView $source,
        public TransactionView $target,
        public ?TransactionView $fee,
        public ?string $exchangeRate,
        public int $version,
        public string $createdAt,
        public string $updatedAt,
        public ?string $voidedAt,
    ) {
    }
}
