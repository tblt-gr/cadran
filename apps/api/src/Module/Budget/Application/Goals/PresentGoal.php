<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Budget\Domain\Goals\Goal;
use App\Module\Budget\Domain\Goals\GoalKind;
use App\Module\Budget\Domain\Goals\GoalVersion;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Domain\ExactDecimal;

final readonly class PresentGoal
{
    public function __construct(private ResolveGoalScope $scope, private ReadReferenceIncome $referenceIncome, private WorkspaceCalendar $calendar)
    {
    }

    /** @param list<GoalVersion> $versions */
    public function summary(Goal $goal, array $versions, int $year, ?GoalStatusView $status = null, ?GoalScopeView $scope = null): GoalSummaryView
    {
        $current = self::forYear($goal, $versions, $year) ?? throw new \LogicException('A listed goal must have a version for the selected year.');
        $scope ??= ($this->scope)($goal->workspace, $goal->scopeType, $goal->scopeRef, true);

        return new GoalSummaryView($goal->id, $goal->label, $goal->kind->value, $goal->metric->value, $goal->direction()->value, $scope, $goal->assetCode->toString(), $goal->state->value, self::version($current), $goal->version, $scope->archived ? ['REFERENCE_ARCHIVED'] : [], $status);
    }

    /** @param list<GoalVersion> $versions */
    public function detail(Goal $goal, array $versions): GoalDetailView
    {
        $current = $versions[0] ?? throw new \LogicException('A goal must have a definition.');
        $scope = ($this->scope)($goal->workspace, $goal->scopeType, $goal->scopeRef, true);
        $reference = null;
        $resolved = null;
        if (GoalKind::ROLLING_RATIO === $goal->kind) {
            $month = CalendarMonth::containing($this->calendar->today());
            $reference = ($this->referenceIncome)($goal->workspace, $month);
            $resolved = [
                'value' => null === $reference->value ? null : ExactDecimal::multiplyForResponse($reference->value, $current->ratio ?? throw new \LogicException('A rolling definition carries a ratio.')),
                'reason' => $reference->reason,
            ];
        }

        return new GoalDetailView($goal->id, $goal->label, $goal->kind->value, $goal->metric->value, $goal->direction()->value, $scope, $goal->assetCode->toString(), $goal->state->value, self::version($current), $goal->version, $scope->archived ? ['REFERENCE_ARCHIVED'] : [], array_map(self::version(...), array_reverse($versions)), $reference, $resolved);
    }

    /** @param list<GoalVersion> $versions */
    private static function forYear(Goal $goal, array $versions, int $year): ?GoalVersion
    {
        foreach ($versions as $version) {
            if (GoalKind::ANNUAL === $goal->kind ? $version->year === $year : $version->effectiveFrom <= sprintf('%04d-12', $year)) {
                return $version;
            }
        }

        return null;
    }

    private static function version(GoalVersion $version): GoalVersionView
    {
        return new GoalVersionView($version->versionNo, $version->effectiveFrom, $version->year, $version->targetAmount?->toString(), $version->ratio?->toString(), null === $version->schedule ? null : GoalScheduleView::of($version->schedule, $version->year));
    }
}
