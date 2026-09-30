<?php

declare(strict_types=1);

namespace App\Module\Budget\UI\Http;

use App\Module\Budget\Application\Goals\GoalCorrectionPreviewMonthView;
use App\Module\Budget\Application\Goals\GoalCorrectionPreviewStateView;
use App\Module\Budget\Application\Goals\GoalCorrectionPreviewView;
use App\Module\Budget\Application\Goals\GoalDetailView;
use App\Module\Budget\Application\Goals\GoalSchedulePartView;
use App\Module\Budget\Application\Goals\GoalScheduleView;
use App\Module\Budget\Application\Goals\GoalScopeView;
use App\Module\Budget\Application\Goals\GoalStatusMonthView;
use App\Module\Budget\Application\Goals\GoalStatusView;
use App\Module\Budget\Application\Goals\GoalSummaryView;
use App\Module\Budget\Application\Goals\GoalVersionChangeView;
use App\Module\Budget\Application\Goals\GoalVersionHistoryItemView;
use App\Module\Budget\Application\Goals\GoalVersionHistoryView;
use App\Module\Budget\Application\Goals\GoalVersionView;

final class GoalRepresentation
{
    /** @return array<string, mixed> */
    public static function summary(GoalSummaryView $goal): array
    {
        return ['id' => $goal->id, 'label' => $goal->label, 'kind' => $goal->kind, 'metric' => $goal->metric, 'direction' => $goal->direction, 'scope' => self::scope($goal->scope), 'assetCode' => $goal->assetCode, 'state' => $goal->state, 'currentVersion' => self::version($goal->currentVersion), 'version' => $goal->version, 'warnings' => $goal->warnings, 'status' => null === $goal->status ? null : self::status($goal->status, false)];
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
    public static function correctionPreview(GoalCorrectionPreviewView $preview): array
    {
        return ['months' => array_map(static fn (GoalCorrectionPreviewMonthView $month): array => [
            'month' => $month->month,
            'before' => self::previewState($month->before),
            'after' => self::previewState($month->after),
        ], $preview->months)];
    }

    /** @return array<string, mixed> */
    private static function previewState(GoalCorrectionPreviewStateView $state): array
    {
        return ['goalVersion' => $state->goalVersion, 'expected' => $state->expected, 'status' => $state->status];
    }

    /** @return array<string, mixed> */
    public static function history(GoalVersionHistoryView $history): array
    {
        return ['items' => array_map(static fn (GoalVersionHistoryItemView $item): array => [
            ...self::version($item->definition),
            'retroactive' => $item->retroactive,
            'reason' => $item->reason,
            'createdAt' => $item->createdAt->format(DATE_ATOM),
            'createdBy' => ['id' => $item->createdById, 'displayName' => $item->createdByDisplayName],
            'changes' => array_map(static fn (GoalVersionChangeView $change): array => ['field' => $change->field, 'before' => $change->before, 'after' => $change->after], $item->changes),
        ], $history->items)];
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

    /** @return array<string, mixed> */
    public static function status(GoalStatusView $status, bool $includeMonths = true): array
    {
        $policy = ['state' => $status->metricPolicy->state, 'version' => $status->metricPolicy->version, 'versions' => $status->metricPolicy->versions];
        $base = [
            'goalId' => $status->goalId,
            'month' => $status->month,
            'kind' => $status->kind,
            'direction' => $status->direction,
            'goalVersion' => $status->goalVersion,
            'provisional' => $status->provisional,
            'metricPolicy' => $policy,
            'target' => $status->target,
            'gap' => $status->gap,
            'status' => $status->status,
            'reason' => $status->reason,
            'quality' => $status->quality,
        ];
        if ('ROLLING_RATIO' === $status->kind) {
            $reference = $status->referenceIncome;

            return [...$base, 'ratio' => $status->ratio, 'referenceIncome' => null === $reference ? null : ['value' => $reference->value?->toString(), 'usableMonths' => $reference->usableMonths, 'windowStart' => $reference->windowStart, 'windowEnd' => $reference->windowEnd], 'actual' => $status->actual];
        }
        $annual = [...$base, 'scheduleMode' => $status->scheduleMode, 'achieved' => $status->achieved, 'expected' => $status->expected, 'remaining' => $status->remaining, 'excess' => $status->excess, 'progressRatio' => $status->progressRatio, 'progressRatioDisplay' => $status->progressRatioDisplay];
        if ($includeMonths) {
            $annual['months'] = array_map(static fn (GoalStatusMonthView $month): array => ['month' => $month->month, 'state' => $month->state, 'value' => $month->value, 'expectedPart' => $month->expectedPart, 'cumulativeValue' => $month->cumulativeValue, 'cumulativeExpected' => $month->cumulativeExpected, 'goalVersion' => $month->goalVersion, 'policyVersion' => $month->policyVersion], $status->months);
        }

        return $annual;
    }
}
