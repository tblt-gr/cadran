<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\Goals;

enum GoalScheduleMode: string
{
    case LINEAR = 'LINEAR';
    case CUSTOM = 'CUSTOM';
}
