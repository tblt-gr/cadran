<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Accounts\Domain\InvalidCalendarMonth;
use App\Module\Budget\Domain\Goals\Goal;
use App\Module\Budget\Domain\Goals\GoalEvaluationRules;
use App\Module\Budget\Domain\Goals\GoalMetric;
use App\Module\Budget\Domain\Goals\GoalPeriodicity;
use App\Module\Budget\Domain\Goals\GoalPrecedence;
use App\Module\Budget\Domain\Goals\GoalReason;
use App\Module\Budget\Domain\Goals\GoalRepository;
use App\Module\Budget\Domain\Goals\GoalValueType;
use App\Module\Budget\Domain\Goals\GoalVersion;
use App\Module\Budget\Domain\Goals\GoalVersionRepository;
use App\Module\Budget\Domain\Goals\GoalVersionResolver;
use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Reporting\Application\AnnualColumn;
use App\Module\Reporting\Application\AnnualColumnSeries;
use App\Module\Reporting\Application\AnnualMonth;
use App\Module\Reporting\Domain\Aggregation\AggregateReason;
use App\Module\Reporting\Domain\Aggregation\ColumnKind;
use App\Module\Reporting\Domain\Aggregation\IncompleteMonths;
use App\Module\Reporting\Domain\Aggregation\MonthState;
use App\Module\Reporting\Domain\Aggregation\MonthValue;
use App\Module\Reporting\Domain\Aggregation\ReportAggregator;

/**
 * Which goal applies to every month (or year) of a range, its target, the achieved value, the
 * gap and the status. The months are loaded once and evaluated in memory; open months can include
 * planned transactions, while closed months use their reporting snapshot.
 */
final readonly class ListGoalEvaluations
{
    public const int MAX_MONTHS = 120;
    /** Calendar years a YEAR-only range may touch: one row per year stays cheap, unlike 360 month rows. */
    public const int MAX_YEARS = 30;
    /** Bounds the goals read for a range; it counts ended goals still covering the range, not only the 100 current ones. */
    public const int MAX_GOALS = 500;

    private GoalEvaluationItems $items;

    public function __construct(
        private CallerWorkspace $caller,
        private GoalRepository $goals,
        private GoalVersionRepository $versions,
        private GoalMetricReader $metrics,
        ResolveGoalScope $scope,
        private WorkspaceCalendar $calendar,
        private ?EvaluateStockGoal $stocks = null,
        private ResolveGoalTarget $targets = new ResolveGoalTarget(),
    ) {
        $this->items = new GoalEvaluationItems($scope);
    }

    public function __invoke(string $from, string $to, GoalEvaluationGranularity $granularity = GoalEvaluationGranularity::BOTH, bool $includeUpcoming = false): GoalEvaluationsView
    {
        try {
            $first = CalendarMonth::fromString($from);
            $last = CalendarMonth::fromString($to);
        } catch (InvalidCalendarMonth) {
            throw new GoalInputError('invalid_goal_query', 'An evaluation range is written YYYY-MM.');
        }
        $span = self::ordinal($last) - self::ordinal($first) + 1;
        if ($span < 1) {
            throw new GoalInputError('invalid_goal_query', 'An evaluation range ends after it starts.');
        }
        if ($granularity->months() && $span > self::MAX_MONTHS) {
            throw new GoalInputError('invalid_goal_query', sprintf('An evaluation range covers from 1 to %d months.', self::MAX_MONTHS));
        }
        if (!$granularity->months() && $last->year - $first->year + 1 > self::MAX_YEARS) {
            throw new GoalInputError('invalid_goal_query', sprintf('A yearly evaluation range covers from 1 to %d years.', self::MAX_YEARS));
        }
        $today = CalendarMonth::containing($this->calendar->today());
        $end = self::ordinal($last) > self::ordinal($today) ? $today : $last;
        $workspace = $this->caller->resolve();
        if (self::ordinal($first) > self::ordinal($today)) {
            return new GoalEvaluationsView([], []);
        }

        $goals = $this->goals->covering($workspace, $first->year.'-01', $end->key(), self::MAX_GOALS + 1);
        if (count($goals) > self::MAX_GOALS) {
            throw new GoalInputError('invalid_goal_query', 'Too many goals cover this range.');
        }
        $monthly = $granularity->months() ? array_values(array_filter($goals, static fn (Goal $goal): bool => GoalPeriodicity::MONTHLY === $goal->periodicity)) : [];
        $yearly = $granularity->years() ? array_values(array_filter($goals, static fn (Goal $goal): bool => GoalPeriodicity::YEARLY === $goal->periodicity)) : [];
        $inputs = $this->metrics->inputsBetween($workspace, [] === $yearly ? $first : new CalendarMonth($first->year, 1), $end, [...$monthly, ...$yearly], $includeUpcoming);
        $context = new EvaluationContext($today, $inputs);
        $context->versions = $this->versions->listByGoals($workspace, array_map(static fn (Goal $goal): string => $goal->id, $goals));

        $months = [];
        for ($ordinal = self::ordinal($first); $granularity->months() && $ordinal <= self::ordinal($end); ++$ordinal) {
            $month = new CalendarMonth(intdiv($ordinal - 1, 12), ($ordinal - 1) % 12 + 1);
            $items = [];
            foreach (GoalPrecedence::winners($monthly, $month->key()) as $winner) {
                $item = $this->month($context, $winner['goal'], $winner['overrides'], $month);
                if (null !== $item) {
                    $items[] = $item;
                }
            }
            $months[] = new GoalPeriodEvaluationView($month->key(), $items);
        }
        $years = [];
        for ($year = $first->year; $granularity->years() && $year <= $end->year; ++$year) {
            $items = [];
            foreach (GoalPrecedence::winners($yearly, (string) $year) as $winner) {
                $item = $this->year($context, $winner['goal'], $winner['overrides'], $year);
                if (null !== $item) {
                    $items[] = $item;
                }
            }
            $years[] = new GoalPeriodEvaluationView((string) $year, $items);
        }

        return new GoalEvaluationsView($months, $years);
    }

    /** @param list<string> $overrides */
    private function month(EvaluationContext $context, Goal $goal, array $overrides, CalendarMonth $month): ?GoalEvaluationView
    {
        $version = GoalVersionResolver::forMonth($this->versionsOf($context, $goal), $month);
        if (null === $version) {
            return null;
        }
        if ($goal->metric->isStock()) {
            return ($this->stocks ?? throw new \LogicException('Stock evaluation reader is required.'))($context, $goal, $version, $overrides, $month->firstDay(), $month->firstDay()->modify('last day of this month'));
        }
        $item = $context->inputs->months[$month->key()] ?? new AnnualMonth($month, MonthState::NO_DATA, false, false, null);
        $figure = $this->monthFigure($context, $goal, $item);
        $target = $this->targets->forMonth($version, $item, $month, $context);
        $complete = MonthState::COMPLETE === $item->state;

        return $this->items->build($context, $goal, $version, $overrides, $target, $figure['value'], $figure['reason'], $complete, null, $goal->assetCode->toString());
    }

    /** @param list<string> $overrides */
    private function year(EvaluationContext $context, Goal $goal, array $overrides, int $year): ?GoalEvaluationView
    {
        $versions = $this->versionsOf($context, $goal);
        $version = GoalVersionResolver::forMonth($versions, $year < $context->today->year ? new CalendarMonth($year, 12) : $context->today);
        if (null === $version) {
            return null;
        }
        if ($goal->metric->isStock()) {
            return ($this->stocks ?? throw new \LogicException('Stock evaluation reader is required.'))($context, $goal, $version, $overrides, new \DateTimeImmutable($year.'-01-01'), new \DateTimeImmutable($year.'-12-31'));
        }
        $included = $year < $context->today->year ? 12 : $context->today->month;
        $months = [];
        for ($index = 1; $index <= 12; ++$index) {
            $month = new CalendarMonth($year, $index);
            if ($index <= $included) {
                $months[] = $context->inputs->months[$month->key()] ?? new AnnualMonth($month, MonthState::NO_DATA, false, false, null);
            }
        }
        $complete = 12 === $included && [] === array_filter($months, static fn (AnnualMonth $month): bool => MonthState::COMPLETE !== $month->state);
        $achieved = $this->yearFigure($context, $goal, $months);
        $yearIncome = null;
        if (GoalValueType::INCOME_SHARE === $version->valueType || GoalValueType::ROLLING_INCOME_SHARE === $version->valueType) {
            $yearIncome = ReportAggregator::flow(AnnualColumnSeries::values('cashIncome', true, $months), IncompleteMonths::INCLUDE)->total?->toString();
        }
        $target = $this->targets->forYear($version, $yearIncome);
        $pace = null;
        $schedule = $version->schedule;
        if (GoalValueType::AMOUNT === $version->valueType && null !== $schedule && null !== $achieved['value']) {
            $expected = '0';
            foreach (array_slice($schedule->parts, 0, $included) as $part) {
                $expected = ExactDecimal::addForResponse($expected, $part->toString());
            }
            $pace = ['expected' => $expected, 'status' => GoalEvaluationRules::pace($goal->direction(), $achieved['value'], $expected)];
        }

        return $this->items->build($context, $goal, $version, $overrides, $target, $achieved['value'], $achieved['reason'], $complete, $pace, $goal->assetCode->toString());
    }

    /** @return array{value: ?string, reason: ?GoalReason} */
    private function monthFigure(EvaluationContext $context, Goal $goal, AnnualMonth $month): array
    {
        $metric = $this->metrics->metric($goal, $month, $context->categoryIds($this->metrics, $goal), $context->inputs);

        return [
            'value' => $metric['value']?->toString(),
            'reason' => null === $metric['value'] ? ($metric['reason'] ?? GoalReason::NO_DATA)->forEvaluation() : null,
        ];
    }

    /**
     * The year aggregate of the reports: flows summed, rates recomputed from summed numerator and denominator.
     *
     * @param list<AnnualMonth> $months
     *
     * @return array{value: ?string, reason: ?GoalReason}
     */
    private function yearFigure(EvaluationContext $context, Goal $goal, array $months): array
    {
        if (in_array($goal->metric, [GoalMetric::TOTAL_INCOME, GoalMetric::TOTAL_SPENDING], true)) {
            if ([] === array_filter($months, static fn (AnnualMonth $month): bool => true === $month->figures?->hasTransactions)) {
                return ['value' => null, 'reason' => GoalReason::NO_DATA];
            }
            $column = new AnnualColumn(GoalMetric::TOTAL_INCOME === $goal->metric ? 'cashIncome' : 'budgetExpenses', '', ColumnKind::FLOW, true);
            $aggregate = AnnualColumnSeries::aggregate($column, $months, IncompleteMonths::INCLUDE);
        } elseif ($goal->metric->isRate()) {
            $column = new AnnualColumn(GoalMetric::SAVINGS_RATE === $goal->metric ? 'cashSavingsRate' : 'netSavingsRate', '', ColumnKind::RATE, true);
            $aggregate = AnnualColumnSeries::aggregate($column, $months, IncompleteMonths::INCLUDE);
        } else {
            $values = [];
            foreach ($months as $month) {
                $figure = $this->monthFigure($context, $goal, $month);
                $values[] = new MonthValue($month->month, $month->state, null === $figure['value'] ? null : DecimalValue::fromString($figure['value']), null === $figure['value'] ? null : $goal->assetCode, $figure['reason']?->value, $month->figures?->policyVersion);
            }
            $aggregate = ReportAggregator::flow($values, IncompleteMonths::INCLUDE);
        }
        if (null !== $aggregate->total) {
            return ['value' => $aggregate->total->toString(), 'reason' => null];
        }

        return ['value' => null, 'reason' => match ($aggregate->totalReason) {
            AggregateReason::MIXED_ASSETS => GoalReason::MIXED_ASSETS,
            AggregateReason::ZERO_DENOMINATOR => GoalReason::ZERO_CASH_INCOME,
            default => GoalReason::NO_DATA,
        }];
    }

    /** @return list<GoalVersion> */
    private function versionsOf(EvaluationContext $context, Goal $goal): array
    {
        return $context->versions[$goal->id] ?? [];
    }

    private static function ordinal(CalendarMonth $month): int
    {
        return $month->year * 12 + $month->month;
    }
}
