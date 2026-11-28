<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\Goals;

enum GoalStatusQuality: string
{
    case COMPLETE = 'COMPLETE';
    case PARTIAL = 'PARTIAL';
    case PROVISIONAL = 'PROVISIONAL';
    case EMPTY = 'EMPTY';
}
