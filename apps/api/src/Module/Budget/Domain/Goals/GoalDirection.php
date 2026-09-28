<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\Goals;

enum GoalDirection: string
{
    case MINIMUM = 'MINIMUM';
    case CEILING = 'CEILING';
}
