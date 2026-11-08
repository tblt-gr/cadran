<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

/** Which periods an evaluation request computes; a caller that reads only one skips the other loop. */
enum GoalEvaluationGranularity: string
{
    case MONTH = 'MONTH';
    case YEAR = 'YEAR';
    case BOTH = 'BOTH';

    public function months(): bool
    {
        return self::YEAR !== $this;
    }

    public function years(): bool
    {
        return self::MONTH !== $this;
    }
}
