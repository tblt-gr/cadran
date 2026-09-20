<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain;

enum BudgetScopeType: string
{
    case CATEGORY = 'CATEGORY';
    case GROUP = 'GROUP';
    case AXIS = 'AXIS';
}
