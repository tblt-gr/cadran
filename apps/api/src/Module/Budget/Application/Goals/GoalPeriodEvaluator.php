<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Budget\Domain\Goals\Goal;
use App\Module\Budget\Domain\Goals\GoalEvaluationRules;
use App\Module\Budget\Domain\Goals\GoalEvaluationStatus;
use App\Module\Budget\Domain\Goals\GoalPolicyState;
use App\Module\Budget\Domain\Goals\GoalReason;
use App\Module\Budget\Domain\Goals\GoalStatusQuality;
use App\Module\Budget\Domain\Goals\GoalVersion;
use App\Module\Budget\Domain\Goals\GoalVersionResolver;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Reporting\Application\AnnualMonth;
use App\Module\Reporting\Domain\Aggregation\AggregateReason;
use App\Module\Reporting\Domain\Aggregation\IncompleteMonths;
use App\Module\Reporting\Domain\Aggregation\MonthState;
use App\Module\Reporting\Domain\Aggregation\MonthValue;
use App\Module\Reporting\Domain\Aggregation\ReportAggregator;

/** Evaluates a goal over its period: the cumulative annual reading and the month-by-month monthly reading. */
final readonly class GoalPeriodEvaluator
{
    public function __construct(private GoalMetricReader $metrics, private ResolveGoalTarget $targets = new ResolveGoalTarget())
    {
    }

    /**
     * @param list<GoalVersion> $versions
     * @param list<string>      $categoryIds
     */
    public function annual(Goal $goal, array $versions, CalendarMonth $selected, bool $archived, array $categoryIds, GoalStatusInputs $inputs): GoalStatusView
    {
        $months = $inputs->months;
        $values = [];
        $rows = [];
        $expectedTotal = '0';
        $cumulativeActual = '0';
        $actualStarted = false;
        $actualMissing = false;
        $expectedMissing = false;
        $selectedVersion = GoalVersionResolver::forMonth($versions, $selected);

        for ($index = 1; $index <= $selected->month; ++$index) {
            $month = new CalendarMonth($selected->year, $index);
            $item = $months[$month->key()] ?? new AnnualMonth($month, MonthState::NO_DATA, false, false, null);
            $version = GoalVersionResolver::forMonth($versions, $month);
            $metric = $this->metrics->metric($goal, $item, $categoryIds, $inputs);
            $values[] = new MonthValue($month, $item->state, $metric['value'], $goal->assetCode, $metric['reason']?->value, $item->figures?->policyVersion);

            $part = $version?->schedule?->parts[$index - 1] ?? null;
            if (null === $part) {
                $expectedMissing = true;
            } else {
                $expectedTotal = ExactDecimal::addForResponse($expectedTotal, $part->toString());
            }
            if (MonthState::COMPLETE === $item->state || MonthState::PROVISIONAL === $item->state) {
                if (null === $metric['value']) {
                    $actualMissing = true;
                } elseif (!$actualMissing) {
                    $cumulativeActual = ExactDecimal::addForResponse($cumulativeActual, $metric['value']->toString());
                    $actualStarted = true;
                }
            }
            $rows[] = new GoalStatusMonthView(
                $month->key(),
                $item->state->value,
                $metric['value']?->toString(),
                $part?->toString(),
                $actualMissing || !$actualStarted ? null : $cumulativeActual,
                $expectedMissing ? null : $expectedTotal,
                $version?->versionNo,
                $item->figures?->policyVersion,
            );
        }

        $qualityValues = array_map(static fn (MonthValue $value): MonthValue => new MonthValue(
            $value->month,
            $value->state,
            null === $value->value ? null : DecimalValue::zero(),
            $value->asset,
            $value->reason,
            $value->policyVersion,
        ), $values);
        $aggregate = ReportAggregator::flow($qualityValues, IncompleteMonths::INCLUDE);
        $policy = GoalMetricReader::policy($values);
        $target = $selectedVersion?->targetAmount?->toString();
        $achieved = null;
        if (null !== $aggregate->total) {
            $achieved = '0';
            foreach ($values as $value) {
                if ((MonthState::COMPLETE === $value->state || MonthState::PROVISIONAL === $value->state) && null !== $value->value) {
                    $achieved = ExactDecimal::addForResponse($achieved, $value->value->toString());
                }
            }
        }
        $reason = match (true) {
            null === $selectedVersion => GoalReason::GOAL_NOT_STARTED,
            AggregateReason::MIXED_METRIC_POLICIES === $aggregate->totalReason => GoalReason::MIXED_METRIC_POLICIES,
            null === $achieved => GoalReason::MISSING_MONTH_VALUE,
            $archived => GoalReason::REFERENCE_ARCHIVED,
            default => null,
        };
        $expected = $expectedMissing || null === $selectedVersion?->schedule ? null : $expectedTotal;
        $complete = 12 === $selected->month
            && [] === array_filter($values, static fn (MonthValue $value): bool => MonthState::COMPLETE !== $value->state);
        $calculable = !self::blocksCalculation($reason) && null !== $achieved && null !== $target;
        $status = $calculable
            ? GoalEvaluationRules::status($goal->direction(), $achieved, $target, $complete, $selectedVersion->toleranceAmount(null))
            : GoalEvaluationStatus::NOT_CALCULABLE;
        $ratio = null !== $achieved && null !== $target ? ExactDecimal::divideForResponse($achieved, $target) : null;

        return new GoalStatusView(
            $goal->id,
            $selected->key(),
            $goal->periodicity->value,
            $goal->direction()->value,
            $selectedVersion?->valueType->value,
            $selectedVersion?->versionNo,
            $selectedVersion?->scheduleMode(),
            MonthState::PROVISIONAL === ($months[$selected->key()]->state ?? null),
            $policy,
            $target,
            $achieved,
            $expected,
            $calculable ? GoalEvaluationRules::gap($goal->direction(), $achieved, $target) : null,
            null !== $achieved && null !== $target ? GoalEvaluationRules::excess($target, $achieved) : null,
            null !== $achieved && null !== $target ? GoalEvaluationRules::excess($achieved, $target) : null,
            $status,
            $ratio,
            null === $ratio ? null : self::clamp($ratio),
            $reason,
            GoalStatusQuality::from($aggregate->quality->value),
            $rows,
            pace: null !== $achieved && null !== $expected ? GoalEvaluationRules::pace($goal->direction(), $achieved, $expected) : null,
            paceGap: GoalEvaluationRules::gap($goal->direction(), $achieved, $expected),
        );
    }

    /**
     * Each month from the goal's start (or January) to the selected month is compared with its own
     * target, so a recurring ceiling is read month by month and never cumulatively.
     *
     * @param list<GoalVersion> $versions
     * @param list<string>      $categoryIds
     */
    public function monthly(Goal $goal, array $versions, CalendarMonth $selected, bool $archived, array $categoryIds, GoalStatusInputs $inputs): GoalStatusView
    {
        $first = max($goal->firstMonth(), $selected->year.'-01');
        $rows = [];
        $selectedEvaluation = null;
        for ($cursor = CalendarMonth::fromString($first); $cursor->key() <= $selected->key(); $cursor = CalendarMonth::containing($cursor->firstDay()->modify('+1 month'))) {
            $evaluation = $this->evaluateMonth($goal, GoalVersionResolver::forMonth($versions, $cursor), $cursor, $categoryIds, $inputs);
            $rows[] = new GoalStatusMonthView($cursor->key(), $evaluation->state->value, $evaluation->actual, $evaluation->target->target, null, null, $evaluation->version?->versionNo, $evaluation->policyVersion);
            $selectedEvaluation = $evaluation;
        }
        $evaluation = $selectedEvaluation ?? throw new \LogicException('A covered month yields at least one evaluation.');
        $version = $evaluation->version;
        $policy = GoalMetricReader::policyFromVersions($evaluation->policyVersions);
        $reason = match (true) {
            null === $version => GoalReason::GOAL_NOT_STARTED,
            null !== $evaluation->reason => $evaluation->reason,
            GoalPolicyState::MIXED === $policy->state => GoalReason::MIXED_METRIC_POLICIES,
            $archived => GoalReason::REFERENCE_ARCHIVED,
            default => null,
        };
        $actual = $evaluation->actual;
        $target = $evaluation->target->target;
        $calculable = !self::blocksCalculation($reason) && null !== $actual && null !== $target;
        $status = $calculable
            ? GoalEvaluationRules::status($goal->direction(), $actual, $target, MonthState::COMPLETE === $evaluation->state, $evaluation->target->tolerance)
            : GoalEvaluationStatus::NOT_CALCULABLE;
        $quality = match (true) {
            GoalPolicyState::MIXED === $policy->state, MonthState::NO_DATA === $evaluation->state => GoalStatusQuality::PARTIAL,
            MonthState::PROVISIONAL === $evaluation->state => GoalStatusQuality::PROVISIONAL,
            default => GoalStatusQuality::COMPLETE,
        };

        return new GoalStatusView(
            $goal->id,
            $selected->key(),
            $goal->periodicity->value,
            $goal->direction()->value,
            $version?->valueType->value,
            $version?->versionNo,
            null,
            MonthState::PROVISIONAL === $evaluation->state,
            $policy,
            $target,
            null,
            null,
            $calculable ? GoalEvaluationRules::gap($goal->direction(), $actual, $target) : null,
            null,
            null,
            $status,
            null,
            null,
            $reason,
            $quality,
            $rows,
            $version?->ratio?->toString(),
            $evaluation->target->reference,
            $actual,
        );
    }

    /** @param list<string> $categoryIds */
    private function evaluateMonth(Goal $goal, ?GoalVersion $version, CalendarMonth $month, array $categoryIds, GoalStatusInputs $inputs): GoalMonthEvaluation
    {
        $item = $inputs->months[$month->key()] ?? new AnnualMonth($month, MonthState::NO_DATA, false, false, null);
        $metric = $this->metrics->metric($goal, $item, $categoryIds, $inputs);
        $policyVersion = $item->figures?->policyVersion;
        $target = null === $version ? new GoalTarget() : $this->targets->forMonth($version, $item, $month, $inputs);
        $reason = $target->reason;
        if (null !== $version && null === $reason && (null === $metric['value'] || MonthState::NO_DATA === $item->state)) {
            $reason = GoalReason::MISSING_MONTH_VALUE;
        }

        return new GoalMonthEvaluation(
            $version,
            $item->state,
            $target,
            $metric['value']?->toString(),
            $reason,
            $policyVersion,
            [...(null === $policyVersion ? [] : [$policyVersion]), ...($target->reference->metricPolicyVersions ?? [])],
        );
    }

    private static function clamp(string $ratio): string
    {
        if (ExactDecimal::compareForResponse($ratio, '0') < 0) {
            return '0';
        }
        if (ExactDecimal::compareForResponse($ratio, '1') > 0) {
            return '1';
        }

        return $ratio;
    }

    private static function blocksCalculation(?GoalReason $reason): bool
    {
        return null !== $reason && GoalReason::REFERENCE_ARCHIVED !== $reason;
    }
}
