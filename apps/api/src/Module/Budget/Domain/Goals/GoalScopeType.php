<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\Goals;

enum GoalScopeType: string
{
    case WORKSPACE = 'WORKSPACE';
    case CATEGORY = 'CATEGORY';
    case AXIS = 'AXIS';
    case ACCOUNT = 'ACCOUNT';
}
