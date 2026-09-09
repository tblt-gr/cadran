<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain;

enum TransactionState: string
{
    case PENDING = 'PENDING';
    case BOOKED = 'BOOKED';
    case VOIDED = 'VOIDED';
    case REJECTED = 'REJECTED';

    public function isTerminal(): bool
    {
        return self::VOIDED === $this || self::REJECTED === $this;
    }
}
