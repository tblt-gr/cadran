<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\Goals;

enum GoalKind: string
{
    case ANNUAL = 'ANNUAL';
    case ROLLING_RATIO = 'ROLLING_RATIO';
}
