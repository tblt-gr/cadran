<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

final readonly class CorrectAccountBalanceInput
{
    public function __construct(
        public mixed $amount,
        public mixed $amountAssetCode,
        public ?string $comment,
        public int $version,
    ) {
    }
}
