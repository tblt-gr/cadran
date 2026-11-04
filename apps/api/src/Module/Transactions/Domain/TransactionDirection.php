<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain;

/** The sign of a movement on its account: money coming in or going out. */
enum TransactionDirection: string
{
    case INFLOW = 'INFLOW';
    case OUTFLOW = 'OUTFLOW';
}
