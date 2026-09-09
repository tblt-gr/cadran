<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain;

enum PaymentMethod: string
{
    case CARD = 'CARD';
    case TRANSFER = 'TRANSFER';
    case DIRECT_DEBIT = 'DIRECT_DEBIT';
    case CHECK = 'CHECK';
    case CASH = 'CASH';
    case OTHER = 'OTHER';
}
