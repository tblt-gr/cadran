<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain;

final readonly class TransactionPosition
{
    public function __construct(
        public \DateTimeImmutable $bookedOn,
        public string $id,
    ) {
    }
}
