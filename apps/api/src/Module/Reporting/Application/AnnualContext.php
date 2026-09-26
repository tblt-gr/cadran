<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reporting\Domain\Aggregation\IncompleteMonths;

/** What a request for one report year has resolved: the workspace, an explicit setting and the first data month. */
final readonly class AnnualContext
{
    public function __construct(
        public WorkspaceScope $workspace,
        public ?IncompleteMonths $incomplete,
        public ?CalendarMonth $firstDataMonth,
    ) {
    }
}
