<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\Goals;

enum GoalEvaluationStatus: string
{
    case MET = 'MET';
    case MISSED = 'MISSED';
    case IN_PROGRESS = 'IN_PROGRESS';
    case NOT_CALCULABLE = 'NOT_CALCULABLE';
}
