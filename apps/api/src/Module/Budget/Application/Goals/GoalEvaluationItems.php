<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Budget\Domain\Goals\Goal;
use App\Module\Budget\Domain\Goals\GoalEvaluationRules;
use App\Module\Budget\Domain\Goals\GoalEvaluationStatus;
use App\Module\Budget\Domain\Goals\GoalMetric;
use App\Module\Budget\Domain\Goals\GoalValueType;
use App\Module\Budget\Domain\Goals\GoalVersion;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;

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
     * @param list<string>                             $overrides
     * @param ?array{expected: string, status: string} $pace
     */
    public function build(EvaluationContext $context, Goal $goal, GoalVersion $version, array $overrides, ?string $target, ?string $targetReason, ?string $achieved, ?string $achievedReason, ?string $income, bool $complete, ?array $pace, string $asset): GoalEvaluationView
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
    public static function share(GoalVersion $version, ?string $income, ?string $incomeReason): array
    {
        if (null === $income || null === $version->ratio) {
            return [null, 'ZERO_CASH_INCOME' === $incomeReason ? 'ZERO_CASH_INCOME' : 'NO_DATA'];
        }
        if (ExactDecimal::isZeroForResponse($income)) {
            return [null, 'ZERO_CASH_INCOME'];
        }

        return [ExactDecimal::multiplyForResponse(DecimalValue::fromString($income), $version->ratio), null];
    }

    private static function columnKey(Goal $goal): ?string
    {
        return match ($goal->metric) {
            GoalMetric::CATEGORY_SPENDING => 'category:'.$goal->scopeRef,
            GoalMetric::AXIS_SPENDING => 'axis:'.$goal->scopeRef,
            GoalMetric::TOTAL_INCOME => 'cashIncome',
            GoalMetric::TOTAL_SPENDING => 'budgetExpenses',
            GoalMetric::NET_SAVINGS_TRANSFERS => 'netSavingsTransfers',
            GoalMetric::BUDGET_SURPLUS => 'budgetSurplus',
            GoalMetric::SAVINGS_RATE => 'cashSavingsRate',
            GoalMetric::NET_SAVINGS_RATE => 'netSavingsRate',
            GoalMetric::ACCOUNT_CONTRIBUTIONS, GoalMetric::ACCOUNT_BALANCE, GoalMetric::NET_WORTH => null,
        };
    }
}
