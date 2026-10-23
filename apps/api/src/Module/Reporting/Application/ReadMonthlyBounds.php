<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Foundation\Application\CallerWorkspace;

final readonly class ReadMonthlyBounds
{
    public function __construct(
        private CallerWorkspace $caller,
        private ReadFirstDataMonth $firstDataMonth,
        private WorkspaceToday $today,
    ) {
    }

    public function __invoke(): MonthlyBoundsView
    {
        $workspace = $this->caller->resolve();

        return new MonthlyBoundsView(($this->firstDataMonth)($workspace), ($this->today)($workspace)->format('Y-m'));
    }
}
