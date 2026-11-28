<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\Goals;

/** How many metric policy versions the figures of a goal period were computed under. */
enum GoalPolicyState: string
{
    case SINGLE = 'SINGLE';
    case MIXED = 'MIXED';
    case NONE = 'NONE';
}
