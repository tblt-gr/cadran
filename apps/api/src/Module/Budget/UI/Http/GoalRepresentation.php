<?php

declare(strict_types=1);

namespace App\Module\Budget\UI\Http;

use App\Module\Budget\Application\Goals\GoalCorrectionPreviewMonthView;
use App\Module\Budget\Application\Goals\GoalCorrectionPreviewStateView;
use App\Module\Budget\Application\Goals\GoalCorrectionPreviewView;
use App\Module\Budget\Application\Goals\GoalDetailView;
use App\Module\Budget\Application\Goals\GoalEvaluationFigure;
use App\Module\Budget\Application\Goals\GoalEvaluationsView;
use App\Module\Budget\Application\Goals\GoalEvaluationView;
use App\Module\Budget\Application\Goals\GoalPeriodEvaluationView;
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
        return ['id' => $goal->id, 'label' => $goal->label, 'periodicity' => $goal->periodicity, 'startPeriod' => $goal->startPeriod, 'endPeriod' => $goal->endPeriod, 'metric' => $goal->metric, 'direction' => $goal->direction, 'scope' => self::scope($goal->scope), 'assetCode' => $goal->assetCode, 'currentVersion' => self::version($goal->currentVersion), 'version' => $goal->version, 'warnings' => $goal->warnings, 'status' => null === $goal->status ? null : self::status($goal->status, false)];
    }

    /** @return array<string, mixed> */
    public static function evaluations(GoalEvaluationsView $evaluations): array
    {
        $period = static fn (GoalPeriodEvaluationView $view): array => ['period' => $view->period, 'items' => array_map(self::evaluation(...), $view->items)];

        return ['months' => array_map($period, $evaluations->months), 'years' => array_map($period, $evaluations->years)];
    }

    /** @return array<string, mixed> */
    private static function evaluation(GoalEvaluationView $item): array
    {
        $figure = static fn (?GoalEvaluationFigure $figure): ?array => null === $figure ? null : ($figure->isRate()
            ? ['ratio' => $figure->ratio, 'percentDisplay' => $figure->percentDisplay]
            : ['value' => $figure->value, 'assetCode' => $figure->assetCode]);

        return [
            'goalId' => $item->goalId, 'label' => $item->label, 'periodicity' => $item->periodicity, 'metric' => $item->metric,
            'scopeType' => $item->scopeType, 'scopeRef' => $item->scopeRef, 'direction' => $item->direction, 'valueType' => $item->valueType,
            'columnKey' => $item->columnKey, 'target' => $figure($item->target), 'achieved' => $figure($item->achieved), 'gap' => $item->gap,
            'status' => $item->status, 'reason' => $item->reason,
            'formula' => ['key' => $item->formulaKey, 'operands' => $item->operands],
            'versionNo' => $item->versionNo, 'overrides' => $item->overrides, 'pace' => $item->pace,
            'tolerance' => $figure($item->tolerance), 'withinTolerance' => $item->withinTolerance,
        ];
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

        return ['versionNo' => $version->versionNo, 'effectiveFrom' => $version->effectiveFrom, 'valueType' => $version->valueType, 'targetAmount' => $version->targetAmount, 'ratio' => $version->ratio, 'tolerance' => $version->tolerance, 'schedule' => $schedule];
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
            'periodicity' => $status->periodicity,
            'valueType' => $status->valueType,
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
        if ('MONTHLY' === $status->periodicity) {
            $reference = $status->referenceIncome;
            $monthly = [...$base, 'ratio' => $status->ratio, 'referenceIncome' => null === $reference ? null : ['value' => $reference->value?->toString(), 'usableMonths' => $reference->usableMonths, 'windowStart' => $reference->windowStart, 'windowEnd' => $reference->windowEnd], 'actual' => $status->actual];
            if ($includeMonths) {
                $monthly['months'] = self::statusMonths($status);
            }

            return $monthly;
        }
        $annual = [...$base, 'scheduleMode' => $status->scheduleMode, 'achieved' => $status->achieved, 'expected' => $status->expected, 'remaining' => $status->remaining, 'excess' => $status->excess, 'progressRatio' => $status->progressRatio, 'progressRatioDisplay' => $status->progressRatioDisplay];
        if ($includeMonths) {
            $annual['months'] = self::statusMonths($status);
        }

        return $annual;
    }

    /** @return list<array<string, mixed>> */
    private static function statusMonths(GoalStatusView $status): array
    {
        return array_map(static fn (GoalStatusMonthView $month): array => ['month' => $month->month, 'state' => $month->state, 'value' => $month->value, 'expectedPart' => $month->expectedPart, 'cumulativeValue' => $month->cumulativeValue, 'cumulativeExpected' => $month->cumulativeExpected, 'goalVersion' => $month->goalVersion, 'policyVersion' => $month->policyVersion], $status->months);
    }
}
