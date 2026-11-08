<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Foundation\Domain\DecimalValue;

final class GoalToleranceInput
{
    /** A missing tolerance is null; a malformed one is a field error, never a silent zero. */
    public static function parse(?string $literal): ?DecimalValue
    {
        if (null === $literal) {
            return null;
        }
        try {
            return DecimalValue::fromString($literal);
        } catch (\InvalidArgumentException|\DomainException) {
            throw new GoalInputError('invalid_goal_tolerance', 'A goal tolerance is a canonical decimal.');
        }
    }
}
