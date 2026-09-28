<?php

declare(strict_types=1);

namespace App\Module\Budget\UI\Http;

use App\Module\Budget\Application\Goals\GoalDetailView;
use App\Module\Budget\Application\Goals\GoalSchedulePartView;
use App\Module\Budget\Application\Goals\GoalScheduleView;
use App\Module\Budget\Application\Goals\GoalScopeView;
use App\Module\Budget\Application\Goals\GoalSummaryView;
use App\Module\Budget\Application\Goals\GoalVersionView;

final class GoalRepresentation
{
    /** @return array<string, mixed> */
    public static function summary(GoalSummaryView $goal): array
    {
        return ['id' => $goal->id, 'label' => $goal->label, 'kind' => $goal->kind, 'metric' => $goal->metric, 'direction' => $goal->direction, 'scope' => self::scope($goal->scope), 'assetCode' => $goal->assetCode, 'state' => $goal->state, 'currentVersion' => self::version($goal->currentVersion), 'version' => $goal->version, 'warnings' => $goal->warnings];
    }

    /** @return array<string, mixed> */
    public static function detail(GoalDetailView $goal): array
    {
        $result = [...self::summary($goal), 'versions' => array_map(self::version(...), $goal->versions)];
        if (null !== $goal->referenceIncome) {
            $reference = $goal->referenceIncome;
            $result['referenceIncome'] = ['month' => $reference->month, 'value' => $reference->value?->toString(), 'usableMonths' => $reference->usableMonths, 'windowStart' => $reference->windowStart, 'windowEnd' => $reference->windowEnd, 'reason' => $reference->reason];
            $result['resolvedMonthlyTarget'] = $goal->resolvedMonthlyTarget;
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private static function scope(GoalScopeView $scope): array
    {
        return array_filter(['type' => $scope->type, 'id' => $scope->id, 'axis' => $scope->axis, 'label' => $scope->label, 'archived' => $scope->archived], static fn (mixed $value): bool => null !== $value);
    }

    /** @return array<string, mixed> */
    private static function version(GoalVersionView $version): array
    {
        $schedule = null === $version->schedule ? null : ['mode' => $version->schedule->mode, 'parts' => array_map(static fn (GoalSchedulePartView $part): array => ['month' => $part->month, 'amount' => $part->amount, 'cumulative' => $part->cumulative], $version->schedule->parts)];

        return ['versionNo' => $version->versionNo, 'effectiveFrom' => $version->effectiveFrom, 'year' => $version->year, 'targetAmount' => $version->targetAmount, 'ratio' => $version->ratio, 'schedule' => $schedule];
    }

    /** @return array<string, mixed> */
    public static function preview(GoalScheduleView $schedule): array
    {
        return ['mode' => $schedule->mode, 'parts' => array_map(static fn (GoalSchedulePartView $part): array => ['monthIndex' => $part->monthIndex, 'amount' => $part->amount, 'cumulative' => $part->cumulative], $schedule->parts)];
    }
}
