<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

final readonly class CreateTransferInput
{
    public function __construct(
        public string $sourceAccountId,
        public string $targetAccountId,
        public mixed $sourceAmount,
        public mixed $targetAmount,
        public string $state,
        public string $bookedOn,
        public ?string $valueOn,
        public string $label,
        public ?string $note,
        public mixed $fee,
    ) {
    }
}
