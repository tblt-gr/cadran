<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\Performance;

use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Portfolio\Application\PortfolioAccountGate;
use App\Module\Portfolio\Domain\Performance\PerformancePeriod;

/** The total gain of a portfolio account over a month, a year or its whole life, with its decomposition. */
final readonly class ReadPortfolioPerformance
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private PortfolioAccountGate $gate,
        private WorkspaceCalendar $calendar,
        private ComputePortfolioPerformance $compute,
    ) {
    }

    public function __invoke(string $accountId, ?string $scope, ?string $periodLiteral): PortfolioPerformance
    {
        $workspace = $this->caller->resolveContext()->workspace;
        $account = $this->gate->forRead($workspace, $accountId);
        $today = $this->calendar->today();

        return ($this->compute)($workspace, $account, PerformancePeriod::of($scope, $periodLiteral, $today, $account->openedOn), $today);
    }
}
