<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

/** The month is not in a state that allows the requested transition. */
class PeriodClosureConflict extends \RuntimeException
{
}
