<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Plan;

/** What a planned step becomes; also the unit of the plan summary. */
enum StepKind
{
    case OPENING_ADJUSTMENT;
    case INCOME;
    case EXPENSE;
    case REFUND;
    case TRANSFER;
    case DEPOSIT;
    case BUY;
    case BALANCE;
    case VALUATION;
}
