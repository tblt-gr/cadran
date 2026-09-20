<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain;

enum BudgetPlanState: string
{
    case DRAFT = 'DRAFT';
    case ACTIVE = 'ACTIVE';
    case CLOSED = 'CLOSED';
}
