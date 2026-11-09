<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Accounts\Domain\InvalidCalendarMonth;
use App\Module\Budget\Domain\Goals\Goal;
use App\Module\Budget\Domain\Goals\GoalEvaluationRules;
use App\Module\Budget\Domain\Goals\GoalEvaluationStatus;
use App\Module\Budget\Domain\Goals\GoalMetric;
use App\Module\Budget\Domain\Goals\GoalPeriodicity;
use App\Module\Budget\Domain\Goals\GoalPrecedence;
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
 * gap and the status. The months are loaded once and evaluated in memory; the figures are the
 * ones the reports show, so a year equals the annual report total of the same column.
 */
final readonly class ListGoalEvaluations
{
    public const int MAX_MONTHS = 120;
    /** Calendar years a YEAR-only range may touch: one row per year stays cheap, unlike 360 month rows. */
    public const int MAX_YEARS = 30;
    /** Bounds the goals read for a range; it counts ended goals still covering the range, not only the 100 current ones. */
    public const int MAX_GOALS = 500;

    public function __construct(
        private CallerWorkspace $caller,
        private GoalRepository $goals,
        private GoalVersionRepository $versions,
        private ReadGoalStatus $readStatus,
        private ResolveGoalScope $scope,
        private WorkspaceCalendar $calendar,
        private ?EvaluateStockGoal $stocks = null,
    ) {
    }

    public function __invoke(string $from, string $to, GoalEvaluationGranularity $granularity = GoalEvaluationGranularity::BOTH): GoalEvaluationsView
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
        $inputs = $this->readStatus->inputsBetween($workspace, [] === $yearly ? $first : new CalendarMonth($first->year, 1), $end, [...$monthly, ...$yearly]);
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
        $asset = $goal->assetCode->toString();
        $income = $item->figures?->cells['cashIncome'] ?? null;
        $target = null;
        $targetReason = null;
        $incomeValue = null;
        switch ($version->valueType) {
            case GoalValueType::AMOUNT:
                $target = $version->targetAmount?->toString();
                break;
            case GoalValueType::RATE:
                $target = $version->ratio?->toString();
                break;
            case GoalValueType::INCOME_SHARE:
                $incomeValue = $income?->value;
                [$target, $targetReason] = $this->share($version, $incomeValue, $income->reason ?? null);
                break;
            case GoalValueType::ROLLING_INCOME_SHARE:
                $reference = $context->reference($month);
                $incomeValue = $reference->value?->toString();
                $target = null !== $reference->value && null !== $version->ratio ? ExactDecimal::multiplyForResponse($reference->value, $version->ratio) : null;
                $targetReason = null === $target ? 'NO_REFERENCE_INCOME' : null;
                break;
        }
        $complete = MonthState::COMPLETE === $item->state;

        return $this->item($context, $goal, $version, $overrides, $target, $targetReason, $figure['value'], $figure['reason'], $incomeValue, $complete, null, $asset);
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
        $target = null;
        $targetReason = null;
        $incomeValue = null;
        switch ($version->valueType) {
            case GoalValueType::AMOUNT:
                $target = $version->targetAmount?->toString();
                break;
            case GoalValueType::RATE:
                $target = $version->ratio?->toString();
                break;
            default:
                $income = ReportAggregator::flow(AnnualColumnSeries::values('cashIncome', true, $months), IncompleteMonths::INCLUDE);
                $incomeValue = $income->total?->toString();
                [$target, $targetReason] = $this->share($version, $incomeValue, null === $income->total ? 'NO_DATA' : null);
        }
        $pace = null;
        $schedule = $version->schedule;
        if (GoalValueType::AMOUNT === $version->valueType && null !== $schedule && null !== $achieved['value']) {
            $expected = '0';
            foreach (array_slice($schedule->parts, 0, $included) as $part) {
                $expected = ExactDecimal::addForResponse($expected, $part->toString());
            }
            $pace = ['expected' => $expected, 'status' => GoalEvaluationRules::pace($goal->direction(), $achieved['value'], $expected)];
        }

        return $this->item($context, $goal, $version, $overrides, $target, $targetReason, $achieved['value'], $achieved['reason'], $incomeValue, $complete, $pace, $goal->assetCode->toString());
    }

    /**
     * @param list<string>                             $overrides
     * @param ?array{expected: string, status: string} $pace
     */
    private function item(EvaluationContext $context, Goal $goal, GoalVersion $version, array $overrides, ?string $target, ?string $targetReason, ?string $achieved, ?string $achievedReason, ?string $income, bool $complete, ?array $pace, string $asset): GoalEvaluationView
    {
        $tolerance = null === $target ? null : $version->toleranceAmount($income);
        $status = GoalEvaluationRules::status($goal->direction(), $achieved, $target, $complete, $tolerance);
        $reason = GoalEvaluationStatus::NOT_CALCULABLE === $status ? ($achievedReason ?? $targetReason ?? 'NO_DATA') : null;
        if (null === $reason && true === $context->scope($this->scope, $goal)->archived) {
            $reason = 'REFERENCE_ARCHIVED';
        }
        $amount = static fn (?string $value): ?GoalEvaluationFigure => null === $value ? null : GoalEvaluationFigure::amount($value, $asset);
        $rate = static fn (?string $value): ?GoalEvaluationFigure => null === $value ? null : GoalEvaluationFigure::rate($value);
        $targetFigure = GoalValueType::RATE === $version->valueType ? $rate($target) : $amount($target);
        $achievedFigure = $goal->metric->isRate() ? $rate($achieved) : $amount($achieved);
        $ratio = $version->ratio?->toString();
        $toleranceFigure = GoalValueType::RATE === $version->valueType ? $rate($tolerance) : $amount($tolerance);

        return new GoalEvaluationView(
            $goal->id, $goal->label, $goal->periodicity->value, $goal->metric->value, $goal->scopeType->value, $goal->scopeRef, $goal->direction()->value,
            $version->valueType->value, self::columnKey($goal), $targetFigure, $achievedFigure,
            GoalEvaluationRules::gap($goal->direction(), $achieved, $target), $status->value, $reason,
            match ($version->valueType) {
                GoalValueType::AMOUNT => 'goals.formula.amount',
                GoalValueType::INCOME_SHARE => 'goals.formula.incomeShare',
                GoalValueType::ROLLING_INCOME_SHARE => 'goals.formula.rollingIncomeShare',
                GoalValueType::RATE => 'goals.formula.rate',
            },
            ['target' => $target, 'achieved' => $achieved, 'income' => $income, 'ratio' => $ratio, 'tolerance' => $tolerance],
            $version->versionNo, $overrides, $pace, $toleranceFigure, GoalEvaluationRules::withinTolerance($goal->direction(), $achieved, $target, $tolerance),
        );
    }

    /** @return array{0: ?string, 1: ?string} the target of a share of income and the reason it has none */
    private function share(GoalVersion $version, ?string $income, ?string $incomeReason): array
    {
        if (null === $income || null === $version->ratio) {
            return [null, 'ZERO_CASH_INCOME' === $incomeReason ? 'ZERO_CASH_INCOME' : 'NO_DATA'];
        }
        if (ExactDecimal::isZeroForResponse($income)) {
            return [null, 'ZERO_CASH_INCOME'];
        }

        return [ExactDecimal::multiplyForResponse(DecimalValue::fromString($income), $version->ratio), null];
    }

    /** @return array{value: ?string, reason: ?string} */
    private function monthFigure(EvaluationContext $context, Goal $goal, AnnualMonth $month): array
    {
        $metric = $this->readStatus->metric($goal, $month, $context->categoryIds($this->readStatus, $goal), $context->inputs);
        if (null === $metric['value'] && 'NO_MOVEMENTS' === $metric['reason']) {
            // The reports count a month without movement as an exact zero in their totals.
            return ['value' => '0', 'reason' => null];
        }

        return ['value' => $metric['value']?->toString(), 'reason' => null === $metric['value'] ? self::publicReason($metric['reason']) : null];
    }

    /**
     * The year aggregate of the reports: flows summed, rates recomputed from summed numerator and denominator.
     *
     * @param list<AnnualMonth> $months
     *
     * @return array{value: ?string, reason: ?string}
     */
    private function yearFigure(EvaluationContext $context, Goal $goal, array $months): array
    {
        if ($goal->metric->isRate()) {
            $column = new AnnualColumn(GoalMetric::SAVINGS_RATE === $goal->metric ? 'cashSavingsRate' : 'netSavingsRate', '', ColumnKind::RATE, true);
            $aggregate = AnnualColumnSeries::aggregate($column, $months, IncompleteMonths::INCLUDE);
        } else {
            $values = [];
            foreach ($months as $month) {
                $figure = $this->monthFigure($context, $goal, $month);
                $values[] = new MonthValue($month->month, $month->state, null === $figure['value'] ? null : DecimalValue::fromString($figure['value']), null === $figure['value'] ? null : $goal->assetCode, $figure['reason'], $month->figures?->policyVersion);
            }
            $aggregate = ReportAggregator::flow($values, IncompleteMonths::INCLUDE);
        }
        if (null !== $aggregate->total) {
            return ['value' => $aggregate->total->toString(), 'reason' => null];
        }

        return ['value' => null, 'reason' => match ($aggregate->totalReason) {
            AggregateReason::MIXED_ASSETS => 'MIXED_ASSETS',
            AggregateReason::ZERO_DENOMINATOR => 'ZERO_CASH_INCOME',
            default => 'NO_DATA',
        }];
    }

    private static function publicReason(?string $reason): string
    {
        return in_array($reason, ['MIXED_ASSETS', 'ZERO_CASH_INCOME'], true) ? $reason : 'NO_DATA';
    }

    private static function columnKey(Goal $goal): ?string
    {
        return match ($goal->metric) {
            GoalMetric::CATEGORY_SPENDING => 'category:'.$goal->scopeRef,
            GoalMetric::AXIS_SPENDING => 'axis:'.$goal->scopeRef,
            GoalMetric::NET_SAVINGS_TRANSFERS => 'netSavingsTransfers',
            GoalMetric::BUDGET_SURPLUS => 'budgetSurplus',
            GoalMetric::SAVINGS_RATE => 'cashSavingsRate',
            GoalMetric::NET_SAVINGS_RATE => 'netSavingsRate',
            GoalMetric::ACCOUNT_CONTRIBUTIONS, GoalMetric::ACCOUNT_BALANCE, GoalMetric::NET_WORTH => null,
        };
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
