<?php

declare(strict_types=1);

namespace App\Module\Categories\Domain;

enum CategoryType: string
{
    case EXPENSE = 'EXPENSE';
    case INCOME = 'INCOME';
}
