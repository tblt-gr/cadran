<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

final readonly class UpdateTransactionInput
{
    public function __construct(
        public string $accountId,
        public mixed $amount,
        public string $nature,
        public string $state,
        public string $bookedOn,
        public ?string $valueOn,
        public ?string $authorizedOn,
        public string $rawLabel,
        public ?string $counterparty,
        public ?string $note,
        public ?string $paymentMethod,
        public ?string $mcc,
        public ?string $maskedCard,
        public ?string $bankReference,
        public ?string $categoryId,
        public int $version,
    ) {
    }
}
