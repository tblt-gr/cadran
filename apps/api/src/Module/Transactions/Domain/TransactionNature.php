<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain;

enum TransactionNature: string
{
    case INCOME = 'INCOME';
    case EXPENSE = 'EXPENSE';
    case TRANSFER = 'TRANSFER';
    case REFUND = 'REFUND';
    case FEE = 'FEE';
    case ADJUSTMENT = 'ADJUSTMENT';
}
