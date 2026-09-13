<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Categorization;

/** Internal signal of {@see RuleMatchingRun}; it never leaves the run. */
final class PatternBudgetExceeded extends \RuntimeException
{
}
