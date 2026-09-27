<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reporting\Domain\Aggregation\IncompleteMonths;

/**
 * Everything {@see ComputeAnnualReport} resolves about the calling workspace that does not depend
 * on a single year: the workspace, the incomplete-month setting, the catalogue and the selected
 * columns, and the first data month. A caller that computes several years — the multi-year report —
 * resolves this once and reuses it across every year, instead of re-reading the catalogue and the
 * preferences once per year.
 */
final readonly class ResolvedAnnualReportContext
{
    /** @param list<AnnualColumn> $columns */
    public function __construct(
        public WorkspaceScope $workspace,
        public IncompleteMonths $incomplete,
        public AnnualCatalogue $catalogue,
        public array $columns,
        public ?CalendarMonth $firstDataMonth,
    ) {
    }
}
