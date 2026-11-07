<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

class GoalSummaryView
{
    /** @param list<string> $warnings */
    public function __construct(
        public string $id, public string $label, public string $periodicity, public string $startPeriod, public ?string $endPeriod,
        public string $metric, public string $direction, public GoalScopeView $scope, public string $assetCode, public GoalVersionView $currentVersion,
        public int $version, public array $warnings, public ?GoalStatusView $status = null,
    ) {
    }
}
