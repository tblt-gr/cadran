<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Transactions\Domain\TransactionState;

final readonly class TransferDraft
{
    public function __construct(
        public TransactionState $state,
        public \DateTimeImmutable $bookedOn,
        public ?\DateTimeImmutable $valueOn,
        public string $label,
    ) {
    }
}
