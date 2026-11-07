<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\Goals;

enum GoalPeriodicity: string
{
    case MONTHLY = 'MONTHLY';
    case YEARLY = 'YEARLY';

    /** Whether a period is written for this periodicity: `YYYY-MM` monthly, `YYYY` yearly. */
    public function accepts(string $period): bool
    {
        return match ($this) {
            self::MONTHLY => 1 === preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/D', $period),
            self::YEARLY => 1 === preg_match('/^[0-9]{4}$/D', $period),
        };
    }
}
