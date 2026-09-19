<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain;

enum MonthlyMovementKind: string
{
    case INCOME = 'INCOME';
    case EXPENSE = 'EXPENSE';
    case REFUND = 'REFUND';
    case FEE = 'FEE';
    case TRANSFER = 'TRANSFER';
    case ADJUSTMENT = 'ADJUSTMENT';
}
