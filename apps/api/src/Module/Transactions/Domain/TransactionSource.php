<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain;

enum TransactionSource: string
{
    case MANUAL = 'MANUAL';
    case IMPORT = 'IMPORT';
    case PROVIDER = 'PROVIDER';
}
