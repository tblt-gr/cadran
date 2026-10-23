<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Accounts\Application\FirstNetWorthDataDayReader;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * The oldest calendar month holding an active balance snapshot, portfolio valuation or booked
 * non-voided transaction, read from the same sources as the net-worth history. Sources dated
 * after the workspace's today never count, and the result is never capped by a chart resolution.
 */
final readonly class ReadFirstDataMonth
{
    public function __construct(
        private FirstNetWorthDataDayReader $firstDay,
        private WorkspaceToday $today,
    ) {
    }

    /** @return string|null `YYYY-MM`, or null when the workspace holds no eligible data */
    public function __invoke(WorkspaceScope $workspace): ?string
    {
        return $this->firstDay->onOrBefore($workspace, ($this->today)($workspace))?->format('Y-m');
    }
}
