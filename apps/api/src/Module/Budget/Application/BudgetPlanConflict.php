<?php

declare(strict_types=1);

namespace App\Module\Budget\Application;

/** Another plan already claims the exact same (workspace, period_type, period), or a concurrent edit lost a version race. */
final class BudgetPlanConflict extends \RuntimeException
{
}
