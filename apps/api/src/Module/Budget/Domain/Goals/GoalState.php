<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\Goals;

enum GoalState: string
{
    case ACTIVE = 'ACTIVE';
    case CLOSED = 'CLOSED';
}
