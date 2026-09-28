<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reporting\Application\AnnualMonth;

interface GoalMonthSource
{
    /** @return list<AnnualMonth> */
    public function between(WorkspaceScope $workspace, CalendarMonth $start, CalendarMonth $end): array;
}
