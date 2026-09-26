<?php

declare(strict_types=1);

namespace App\Module\Budget\Application;

use App\Module\Accounts\Application\NetWorthScopeTooLarge;
use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Budget\Domain\BudgetComparisonCalculator;
use App\Module\Budget\Domain\BudgetComparisonReason;
use App\Module\Budget\Domain\BudgetOverlapDetector;
use App\Module\Budget\Domain\BudgetPeriodType;
use App\Module\Budget\Domain\BudgetPlan;
use App\Module\Budget\Domain\BudgetPlanRepository;
use App\Module\Budget\Domain\BudgetScopeType;
use App\Module\Budget\Domain\BudgetTarget;
use App\Module\Budget\Domain\BudgetTargetRepository;
use App\Module\Budget\Domain\BudgetValueType;
use App\Module\Categories\Application\BudgetCategoryScopeTooLarge;
use App\Module\Categories\Application\CategoryReferenceFact;
use App\Module\Categories\Application\ReadCategoryReference;
use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Reporting\Application\MonthlyBudgetActualView;
use App\Module\Reporting\Application\MonthlyBudgetScope;
use App\Module\Reporting\Application\MonthlyMetricView;
use App\Module\Reporting\Application\ReadMonthlyBudgetActuals;
use App\Module\Reporting\Application\ResolveMetricPolicy;
use App\Module\Reporting\Domain\MonthlyBudgetActualCalculator;
use App\Module\Reporting\Domain\MonthlyProjectionReason;
use App\Module\Transactions\Application\MonthlyTransactionScopeTooLarge;

/** Compares each target independently; no combined total can double-count overlaps. */
final readonly class ReadBudgetComparisons
{
    public function __construct(
        private CallerWorkspace $caller,
        private BudgetPlanRepository $plans,
        private BudgetTargetRepository $targets,
        private ReadCategoryReference $categoryReference,
        private ReadMonthlyBudgetActuals $actuals,
        private ResolveMetricPolicy $metricPolicy,
    ) {
    }

    public function __invoke(string $planId): BudgetComparisonsView
    {
        $workspace = $this->caller->resolve();
        $plan = $this->plans->find($workspace, $planId);
        if (null === $plan) {
            throw new BudgetPlanNotFound('This budget plan does not exist in this workspace.');
        }
        if (BudgetPeriodType::MONTH !== $plan->period->type) {
            throw new BudgetComparisonUnavailable('Budget comparisons are available for monthly plans only.');
        }

        $targets = $this->targets->listByPlan($workspace, $plan->id, BudgetOverlapDetector::MAX_TARGETS + 1);
        if (count($targets) > BudgetOverlapDetector::MAX_TARGETS) {
            throw new BudgetComparisonScopeTooLarge('A budget comparison reads at most 500 targets.');
        }
        if ([] === $targets) {
            return new BudgetComparisonsView(
                $plan->id,
                $plan->period->key(),
                $plan->assetCode->toString(),
                'NO_TARGETS',
                BudgetComparisonReason::MISSING_TARGET->value,
                [],
                ($this->metricPolicy)($workspace, CalendarMonth::fromString($plan->period->key()))->reference(),
            );
        }

        /** @var array<string, CategoryReferenceFact|null> $categoryReferences */
        $categoryReferences = [];
        foreach ($targets as $target) {
            if (BudgetScopeType::AXIS !== $target->scopeType && !array_key_exists($target->scopeId, $categoryReferences)) {
                $categoryReferences[$target->scopeId] = ($this->categoryReference)($workspace, $target->scopeId);
            }
        }
        $overlaps = BudgetOverlapDetector::detect(
            $targets,
            static function (string $categoryId) use ($categoryReferences): array {
                $category = $categoryReferences[$categoryId] ?? null;

                return $category instanceof CategoryReferenceFact ? $category->ancestorIds : [];
            },
        );
        $scopes = array_map(
            static fn (BudgetTarget $target): MonthlyBudgetScope => new MonthlyBudgetScope(
                $target->id,
                $target->scopeType->value,
                $target->scopeId,
            ),
            $targets,
        );

        try {
            $actuals = ($this->actuals)(
                $workspace,
                CalendarMonth::fromString($plan->period->key()),
                $scopes,
                $plan->assetCode->toString(),
            );
        } catch (MonthlyTransactionScopeTooLarge|BudgetCategoryScopeTooLarge|NetWorthScopeTooLarge $exception) {
            throw new BudgetComparisonScopeTooLarge('The monthly budget comparison scope exceeds its bounds.', previous: $exception);
        }

        $views = [];
        foreach ($targets as $target) {
            $actual = $actuals->actuals[$target->id] ?? throw new \LogicException('A requested budget scope has no reporting actual.');
            [$actualValue, $actualReason] = self::actual($actual, $plan);
            [$targetValue, $targetReason] = self::target($target, $plan, $actuals->cashIncome);
            $result = BudgetComparisonCalculator::compare(
                $actualValue,
                $targetValue,
                $actualReason ?? $targetReason,
            );
            $views[] = new BudgetComparisonView(
                $target->id,
                $target->scopeType->value,
                $target->scopeId,
                BudgetScopeLabel::resolve($target, $categoryReferences[$target->scopeId] ?? null),
                $actualValue,
                $actualReason?->value,
                $targetValue,
                $targetReason?->value,
                $result->variance,
                $result->status,
                $actual->sources,
                $actual->pendingCount,
                $overlaps[$target->id] ?? false,
                MonthlyBudgetActualCalculator::POLICY_DESCRIPTION,
            );
        }

        return new BudgetComparisonsView(
            $plan->id,
            $plan->period->key(),
            $plan->assetCode->toString(),
            'AVAILABLE',
            null,
            $views,
            $actuals->metricPolicy,
        );
    }

    /** @return array{?string, ?BudgetComparisonReason} */
    private static function actual(MonthlyBudgetActualView $actual, BudgetPlan $plan): array
    {
        if (null === $actual->amount->value) {
            return [null, self::reason($actual->amount->reason)];
        }
        if ($actual->amount->assetCode !== $plan->assetCode->toString()) {
            return [null, BudgetComparisonReason::MIXED_ASSETS];
        }

        return [$actual->amount->value, null];
    }

    /** @return array{?string, ?BudgetComparisonReason} */
    private static function target(BudgetTarget $target, BudgetPlan $plan, MonthlyMetricView $cashIncome): array
    {
        if (BudgetValueType::AMOUNT === $target->valueType) {
            return [$target->amount?->toString(), null];
        }
        if (null === $cashIncome->value) {
            return [null, self::reason($cashIncome->reason)];
        }
        if ($cashIncome->assetCode !== $plan->assetCode->toString()) {
            return [null, BudgetComparisonReason::MIXED_ASSETS];
        }

        if (ExactDecimal::isZeroForResponse($cashIncome->value)) {
            return [null, BudgetComparisonReason::ZERO_CASH_INCOME];
        }

        return [ExactDecimal::multiplyCanonicalForResponse(
            $cashIncome->value,
            $target->ratio ?? throw new \LogicException('A ratio target has no ratio.'),
        ), null];
    }

    private static function reason(?string $reason): BudgetComparisonReason
    {
        return match ($reason) {
            MonthlyProjectionReason::NO_ACCOUNT->value => BudgetComparisonReason::NO_ACCOUNT,
            MonthlyProjectionReason::ZERO_CASH_INCOME->value => BudgetComparisonReason::ZERO_CASH_INCOME,
            MonthlyProjectionReason::MIXED_ASSETS->value => BudgetComparisonReason::MIXED_ASSETS,
            MonthlyProjectionReason::UNKNOWN_METRIC_POLICY->value => BudgetComparisonReason::UNKNOWN_METRIC_POLICY,
            default => throw new \LogicException('A non-calculable budget metric has no supported reason.'),
        };
    }
}
