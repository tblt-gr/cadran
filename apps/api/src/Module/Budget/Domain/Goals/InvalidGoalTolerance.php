<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\Goals;

/** A tolerance outside [0, target] or beyond eight decimal places. */
final class InvalidGoalTolerance extends InvalidGoal
{
}
