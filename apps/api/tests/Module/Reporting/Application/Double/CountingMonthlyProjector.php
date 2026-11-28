<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\Application\Double;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reporting\Application\MonthlyProjectionView;
use App\Module\Reporting\Application\MonthlyProjector;
use App\Module\Reporting\Application\ResolvedMetricPolicy;

final class CountingMonthlyProjector implements MonthlyProjector
{
    public int $calls = 0;
    public bool $definitionAvailable = true;

    /** @var array<string, ?ResolvedMetricPolicy> the policy handed to each projected month */
    public array $policies = [];

    public function __invoke(WorkspaceScope $workspace, CalendarMonth $month, \DateTimeImmutable $today, bool $includeUpcoming = false, ?ResolvedMetricPolicy $policy = null): MonthlyProjectionView
    {
        ++$this->calls;
        $this->policies[$month->key()] = $policy;

        return MonthlyProjectionFixture::view($month->key(), policyLabel: $this->definitionAvailable ? 'All accounts' : null);
    }
}
