<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\Goals;

/** Where the achieved value of a running yearly goal stands against what was expected by now. */
enum GoalPace: string
{
    case AHEAD = 'AHEAD';
    case BEHIND = 'BEHIND';
    case ON_TRACK = 'ON_TRACK';
    case OVER_PACE = 'OVER_PACE';
}
