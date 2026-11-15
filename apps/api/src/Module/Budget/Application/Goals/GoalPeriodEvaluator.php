<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Budget\Domain\Goals\Goal;
use App\Module\Budget\Domain\Goals\GoalDirection;
use App\Module\Budget\Domain\Goals\GoalValueType;
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
    public function __construct(private GoalMetricReader $metrics)
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
            $values[] = new MonthValue($month, $item->state, $metric['value'], $goal->assetCode, $metric['reason'], $item->figures?->policyVersion);

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
        $achievedResponse = null;
        if (null !== $aggregate->total) {
            $achievedResponse = '0';
            foreach ($values as $value) {
                if ((MonthState::COMPLETE === $value->state || MonthState::PROVISIONAL === $value->state) && null !== $value->value) {
                    $achievedResponse = ExactDecimal::addForResponse($achievedResponse, $value->value->toString());
                }
            }
        }
        $reason = match (true) {
            null === $selectedVersion => 'GOAL_NOT_STARTED',
            AggregateReason::MIXED_METRIC_POLICIES === $aggregate->totalReason => 'MIXED_METRIC_POLICIES',
            null === $achievedResponse || $expectedMissing => 'MISSING_MONTH_VALUE',
            $archived => 'REFERENCE_ARCHIVED',
            default => null,
        };
        $expected = $expectedMissing ? null : $expectedTotal;
        $gap = null !== $achievedResponse && null !== $expected ? ExactDecimal::subtractForResponse($achievedResponse, $expected) : null;
        $remaining = null !== $achievedResponse && null !== $target ? self::positiveForResponse(ExactDecimal::subtractForResponse($target, $achievedResponse)) : null;
        $excess = null !== $achievedResponse && null !== $target ? self::positiveForResponse(ExactDecimal::subtractForResponse($achievedResponse, $target)) : null;
        $status = self::blocksCalculation($reason) || null === $target || null === $achievedResponse || null === $expected
            ? 'NOT_CALCULABLE'
            : self::annualStatus($goal->direction(), $achievedResponse, $expected, $target, $selectedVersion->toleranceAmount(null));
        $ratio = null !== $achievedResponse && null !== $target ? ExactDecimal::divideForResponse($achievedResponse, $target) : null;

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
            $achievedResponse,
            $expected,
            $gap,
            $remaining,
            $excess,
            $status,
            $ratio,
            null === $ratio ? null : self::clamp($ratio),
            $reason,
            $aggregate->quality->value,
            $rows,
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
            $rows[] = new GoalStatusMonthView($cursor->key(), $evaluation['state'], $evaluation['actual'], $evaluation['target'], null, null, $evaluation['version']?->versionNo, $evaluation['policyVersion']);
            $selectedEvaluation = $evaluation;
        }
        $evaluation = $selectedEvaluation ?? throw new \LogicException('A covered month yields at least one evaluation.');
        $version = $evaluation['version'];
        $policy = GoalMetricReader::policyFromVersions($evaluation['policyVersions']);
        $reason = match (true) {
            null === $version => 'GOAL_NOT_STARTED',
            null !== $evaluation['reason'] => $evaluation['reason'],
            'MIXED' === $policy->state => 'MIXED_METRIC_POLICIES',
            $archived => 'REFERENCE_ARCHIVED',
            default => null,
        };
        $actual = $evaluation['actual'];
        $target = $evaluation['target'];
        $calculable = !self::blocksCalculation($reason) && null !== $actual && null !== $target;
        $gap = $calculable ? ExactDecimal::subtractForResponse($actual, $target) : null;
        $quality = match (true) {
            'MIXED' === $policy->state, MonthState::NO_DATA->value === $evaluation['state'] => 'PARTIAL',
            MonthState::PROVISIONAL->value === $evaluation['state'] => 'PROVISIONAL',
            default => 'COMPLETE',
        };

        return new GoalStatusView(
            $goal->id,
            $selected->key(),
            $goal->periodicity->value,
            $goal->direction()->value,
            $version?->valueType->value,
            $version?->versionNo,
            null,
            MonthState::PROVISIONAL->value === $evaluation['state'],
            $policy,
            $target,
            null,
            null,
            $gap,
            null,
            null,
            $calculable ? self::rollingStatus($goal->direction(), $actual, $target, $evaluation['tolerance']) : 'NOT_CALCULABLE',
            null,
            null,
            $reason,
            $quality,
            $rows,
            $version?->ratio?->toString(),
            GoalValueType::ROLLING_INCOME_SHARE === $version?->valueType ? $evaluation['reference'] : null,
            $actual,
        );
    }

    /**
     * The target and the figure of one month of a monthly goal.
     *
     * @param list<string> $categoryIds
     *
     * @return array{version: ?GoalVersion, state: string, target: ?string, tolerance: ?string, actual: ?string, reason: ?string, policyVersion: ?int, policyVersions: list<int>, reference: ?ReferenceIncomeView}
     */
    private function evaluateMonth(Goal $goal, ?GoalVersion $version, CalendarMonth $month, array $categoryIds, GoalStatusInputs $inputs): array
    {
        $item = $inputs->months[$month->key()] ?? new AnnualMonth($month, MonthState::NO_DATA, false, false, null);
        $metric = $this->metrics->metric($goal, $item, $categoryIds, $inputs);
        $policyVersions = null === $item->figures?->policyVersion ? [] : [$item->figures->policyVersion];
        $result = ['version' => $version, 'state' => $item->state->value, 'target' => null, 'actual' => $metric['value']?->toString(), 'reason' => null, 'policyVersion' => $item->figures?->policyVersion, 'policyVersions' => $policyVersions, 'reference' => null, 'tolerance' => null];
        if (null === $version) {
            return $result;
        }
        $ratio = $version->ratio;
        switch ($version->valueType) {
            case GoalValueType::AMOUNT:
                $result['target'] = $version->targetAmount?->toString();
                $result['tolerance'] = $version->toleranceAmount(null);
                break;
            case GoalValueType::RATE:
                $result['target'] = $ratio?->toString();
                $result['tolerance'] = $version->toleranceAmount(null);
                break;
            case GoalValueType::INCOME_SHARE:
                $income = $item->figures?->cells['cashIncome'] ?? null;
                $result['target'] = null !== $income?->value && null !== $ratio ? ExactDecimal::multiplyForResponse(DecimalValue::fromString($income->value), $ratio) : null;
                $result['reason'] = null === $result['target'] ? ($income->reason ?? 'MISSING_CASH_INCOME') : null;
                $result['tolerance'] = null === $result['target'] ? null : $version->toleranceAmount($income?->value);
                break;
            case GoalValueType::ROLLING_INCOME_SHARE:
                $reference = ReadReferenceIncome::fromMonths($month, array_values($inputs->months));
                $result['reference'] = $reference;
                $result['policyVersions'] = [...$policyVersions, ...$reference->metricPolicyVersions];
                $result['target'] = null !== $reference->value && null !== $ratio ? ExactDecimal::multiplyForResponse($reference->value, $ratio) : null;
                $result['reason'] = null === $reference->value ? 'NO_REFERENCE_INCOME' : null;
                $result['tolerance'] = null === $result['target'] ? null : $version->toleranceAmount($reference->value?->toString());
                break;
        }
        if (null === $result['reason'] && (null === $metric['value'] || MonthState::NO_DATA === $item->state)) {
            $result['reason'] = 'MISSING_MONTH_VALUE';
        }

        return $result;
    }

    private static function annualStatus(GoalDirection $direction, string $achieved, string $expected, string $target, ?string $tolerance): string
    {
        $tolerance ??= '0';
        if (GoalDirection::MINIMUM === $direction) {
            return ExactDecimal::compareForResponse($achieved, ExactDecimal::subtractForResponse($target, $tolerance)) >= 0 ? 'REACHED' : (ExactDecimal::compareForResponse($achieved, $expected) >= 0 ? 'AHEAD' : 'BEHIND');
        }

        return ExactDecimal::compareForResponse($achieved, ExactDecimal::addForResponse($target, $tolerance)) > 0 ? 'EXCEEDED' : (ExactDecimal::compareForResponse($achieved, $expected) <= 0 ? 'ON_TRACK' : 'OVER_PACE');
    }

    private static function rollingStatus(GoalDirection $direction, string $actual, string $target, ?string $tolerance): string
    {
        $tolerance ??= '0';

        return GoalDirection::MINIMUM === $direction
            ? (ExactDecimal::compareForResponse($actual, ExactDecimal::subtractForResponse($target, $tolerance)) >= 0 ? 'MET' : 'MISSED')
            : (ExactDecimal::compareForResponse($actual, ExactDecimal::addForResponse($target, $tolerance)) <= 0 ? 'WITHIN' : 'OVER');
    }

    private static function positiveForResponse(string $value): string
    {
        return ExactDecimal::compareForResponse($value, '0') <= 0 ? '0' : $value;
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

    private static function blocksCalculation(?string $reason): bool
    {
        return null !== $reason && 'REFERENCE_ARCHIVED' !== $reason;
    }
}
