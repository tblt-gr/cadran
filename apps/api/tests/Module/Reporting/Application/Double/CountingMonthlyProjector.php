<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\Application\Double;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reporting\Application\MonthlyProjectionView;
use App\Module\Reporting\Application\MonthlyProjector;

final class CountingMonthlyProjector implements MonthlyProjector
{
    public int $calls = 0;

    public function __invoke(WorkspaceScope $workspace, CalendarMonth $month, \DateTimeImmutable $today): MonthlyProjectionView
    {
        ++$this->calls;

        return MonthlyProjectionFixture::view($month->key());
    }
}
