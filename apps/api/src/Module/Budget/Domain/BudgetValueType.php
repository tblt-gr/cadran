<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain;

enum BudgetValueType: string
{
    case AMOUNT = 'AMOUNT';
    case RATIO = 'RATIO';
}
