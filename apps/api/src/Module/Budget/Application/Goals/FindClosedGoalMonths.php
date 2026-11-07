<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Accounts\Domain\PeriodClosureRepository;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * The closed months inside a range of a goal. It runs inside the caller's transaction: the shared
 * lock it takes serialises the answer against a concurrent closing or reopening.
 */
final readonly class FindClosedGoalMonths
{
    public function __construct(private PeriodClosureRepository $closures)
    {
    }

    /** Whether any month from `$first` through `$last` (`YYYY-MM`) is closed. */
    public function anyBetween(WorkspaceScope $workspace, string $first, string $last): bool
    {
        $this->closures->lockShared($workspace);
        if ($first > $last) {
            return false;
        }

        return [] !== $this->closures->activeBetween($workspace, CalendarMonth::fromString($first), CalendarMonth::fromString($last));
    }
}
