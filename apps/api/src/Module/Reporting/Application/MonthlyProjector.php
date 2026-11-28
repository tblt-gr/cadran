<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Domain\WorkspaceScope;

/** Computes the projection of one month live; the seam that lets a test count how often a year pays for it. */
interface MonthlyProjector
{
    /** @param ?ResolvedMetricPolicy $policy the policy of the month when the caller already resolved it */
    public function __invoke(WorkspaceScope $workspace, CalendarMonth $month, \DateTimeImmutable $today, bool $includeUpcoming = false, ?ResolvedMetricPolicy $policy = null): MonthlyProjectionView;
}
