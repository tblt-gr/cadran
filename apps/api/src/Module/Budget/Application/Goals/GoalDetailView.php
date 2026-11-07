<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

final class GoalDetailView extends GoalSummaryView
{
    /**
     * @param list<string>                                $warnings
     * @param list<GoalVersionView>                       $versions
     * @param array{value: ?string, reason: ?string}|null $resolvedMonthlyTarget
     */
    public function __construct(
        string $id, string $label, string $periodicity, string $startPeriod, ?string $endPeriod, string $metric, string $direction, GoalScopeView $scope, string $assetCode,
        GoalVersionView $currentVersion, int $version, array $warnings, public array $versions,
        public ?ReferenceIncomeView $referenceIncome, public ?array $resolvedMonthlyTarget,
    ) {
        parent::__construct($id, $label, $periodicity, $startPeriod, $endPeriod, $metric, $direction, $scope, $assetCode, $currentVersion, $version, $warnings);
    }
}
