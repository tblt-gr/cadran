<?php

declare(strict_types=1);

namespace App\Module\Budget\Application;

/** A plan with no target carries no commitment worth activating. */
final class EmptyBudgetPlan extends \RuntimeException
{
}
