<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Budget\Domain\Goals\Goal;
use App\Module\Budget\Domain\Goals\GoalEvaluationRules;
use App\Module\Budget\Domain\Goals\GoalEvaluationStatus;
use App\Module\Budget\Domain\Goals\GoalMetric;
use App\Module\Budget\Domain\Goals\GoalPace;
use App\Module\Budget\Domain\Goals\GoalReason;
use App\Module\Budget\Domain\Goals\GoalValueType;
use App\Module\Budget\Domain\Goals\GoalVersion;
use App\Module\Reporting\Application\Facade\ReportFigureKey;

/**
 * Builds the evaluation row of one goal for one period: target and achieved figures, gap, status,
 * reason and the formula trace. Split from {@see ListGoalEvaluations}, which decides which goals
 * and periods are evaluated.
 */
final readonly class GoalEvaluationItems
{
    public function __construct(private ResolveGoalScope $scope)
    {
    }

    /**
     * @param list<string>                               $overrides
     * @param ?array{expected: string, status: GoalPace} $pace
     */
    public function build(EvaluationContext $context, Goal $goal, GoalVersion $version, array $overrides, GoalTarget $resolved, ?string $achieved, ?GoalReason $achievedReason, bool $complete, ?array $pace, string $asset): GoalEvaluationView
    {
        $target = $resolved->target;
        $tolerance = $resolved->tolerance;
        $income = $resolved->income;
        $status = GoalEvaluationRules::status($goal->direction(), $achieved, $target, $complete, $tolerance);
        $reason = GoalEvaluationStatus::NOT_CALCULABLE === $status ? ($achievedReason ?? $resolved->reason ?? GoalReason::NO_DATA) : null;
        if (null === $reason && true === $context->scope($this->scope, $goal)->archived) {
            $reason = GoalReason::REFERENCE_ARCHIVED;
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
            GoalEvaluationRules::gap($goal->direction(), $achieved, $target), $status, $reason,
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

    private static function columnKey(Goal $goal): ?string
    {
        return match ($goal->metric) {
            GoalMetric::CATEGORY_SPENDING => 'category:'.$goal->scopeRef,
            GoalMetric::AXIS_SPENDING => ReportFigureKey::axisSpending($goal->scopeRef ?? throw new \LogicException('An axis goal carries its axis.'))->id,
            GoalMetric::TOTAL_INCOME => ReportFigureKey::cashIncome()->id,
            GoalMetric::TOTAL_SPENDING => ReportFigureKey::budgetExpenses()->id,
            GoalMetric::NET_SAVINGS_TRANSFERS => ReportFigureKey::netSavingsTransfers()->id,
            GoalMetric::BUDGET_SURPLUS => ReportFigureKey::budgetSurplus()->id,
            GoalMetric::SAVINGS_RATE => ReportFigureKey::cashSavingsRate()->id,
            GoalMetric::NET_SAVINGS_RATE => ReportFigureKey::netSavingsRate()->id,
            GoalMetric::ACCOUNT_CONTRIBUTIONS, GoalMetric::ACCOUNT_BALANCE, GoalMetric::NET_WORTH => null,
        };
    }
}
