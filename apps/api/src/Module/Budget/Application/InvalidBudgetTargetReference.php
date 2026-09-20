<?php

declare(strict_types=1);

namespace App\Module\Budget\Application;

/** The scope a target points to is archived, unknown, or outside this workspace. */
final class InvalidBudgetTargetReference extends \InvalidArgumentException
{
}
