<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Budget\Domain\Goals\Goal;
use App\Module\Budget\Domain\Goals\GoalMetric;
use App\Module\Budget\Domain\Goals\GoalPeriodicity;
use App\Module\Categories\Application\ReadBudgetCategoryFacts;
use App\Module\Categories\Domain\Category;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reporting\Application\AnnualMonth;
use App\Module\Reporting\Application\MonthFigures;
use App\Module\Reporting\Application\MonthlyMetricView;
use App\Module\Reporting\Domain\Aggregation\MonthState;
use App\Module\Reporting\Domain\Aggregation\MonthValue;
use App\Module\Transactions\Application\ReadMonthlyAccountTransferNet;

/** Reads the facts a goal status is computed from, and the single figure a goal metric takes in one month. */
final readonly class GoalMetricReader
{
    public function __construct(
        private GoalMonthSource $months,
        private ReadMonthlyAccountTransferNet $accountTransfers,
        private ?ReadBudgetCategoryFacts $categories = null,
    ) {
    }

    /**
     * Loads once what the statuses of these goals at this month read: the reporting months from
     * January (or from the rolling reference window), the reference income, the category tree and,
     * for account goals, one transfer-pair read per month of the year.
     *
     * @param list<Goal> $goals
     */
    public function inputs(WorkspaceScope $workspace, CalendarMonth $month, array $goals): GoalStatusInputs
    {
        return $this->inputsBetween($workspace, new CalendarMonth($month->year, 1), $month, $goals);
    }

    /**
     * The same facts for any run of months, which an evaluation over several years reads at once.
     *
     * @param list<Goal> $goals
     */
    public function inputsBetween(WorkspaceScope $workspace, CalendarMonth $first, CalendarMonth $last, array $goals, bool $includeUpcoming = false): GoalStatusInputs
    {
        if ([] === $goals) {
            return new GoalStatusInputs([], null);
        }
        $monthly = [] !== array_filter($goals, static fn (Goal $goal): bool => GoalPeriodicity::MONTHLY === $goal->periodicity);
        $categorySpending = [] !== array_filter($goals, static fn (Goal $goal): bool => GoalMetric::CATEGORY_SPENDING === $goal->metric && null !== $goal->scopeRef);
        // A monthly goal compares every month of the year with its own target, and a rolling share
        // reads the twelve months before each of them: the window opens before January.
        $start = $monthly ? ReadReferenceIncome::windowStart($first) : $first;
        $loaded = $this->months->between($workspace, $start, $last, $includeUpcoming);
        $indexed = [];
        foreach ($loaded as $item) {
            $indexed[$item->month->key()] = $item;
        }

        return new GoalStatusInputs(
            $indexed,
            $categorySpending && null !== $this->categories ? ($this->categories)($workspace) : null,
            $this->accountTransfers($workspace, $first, $last, $goals, $indexed),
        );
    }

    /**
     * An oversized month makes only the account goals of that month not calculable; it never fails
     * the statuses of the other goals read alongside them.
     *
     * @param list<Goal>                 $goals
     * @param array<string, AnnualMonth> $months
     *
     * @return array<string, array<string, array{value: ?DecimalValue, reason: ?string, asset: ?AssetCode}>>
     */
    private function accountTransfers(WorkspaceScope $workspace, CalendarMonth $first, CalendarMonth $last, array $goals, array $months): array
    {
        $accountIds = [];
        foreach ($goals as $goal) {
            if (GoalMetric::ACCOUNT_CONTRIBUTIONS === $goal->metric) {
                $accountIds[$goal->scopeRef ?? throw new \LogicException('An account goal carries its account.')] = true;
            }
        }
        if ([] === $accountIds) {
            return [];
        }
        $accountIds = array_keys($accountIds);
        $transfers = [];
        $readable = [];
        for ($ordinal = GoalCalendar::ordinal($first); $ordinal <= GoalCalendar::ordinal($last); ++$ordinal) {
            $month = new CalendarMonth(intdiv($ordinal - 1, 12), ($ordinal - 1) % 12 + 1);
            $state = $months[$month->key()]->state ?? MonthState::NO_DATA;
            if (MonthState::NO_DATA !== $state && MonthState::FUTURE !== $state) {
                $readable[] = $month;
            }
        }
        // One pair read for the whole range; a month over the monthly bound makes only its account goals not calculable.
        foreach ($this->accountTransfers->forAccountsInMonths($workspace, $accountIds, $readable) as $key => $nets) {
            foreach ($accountIds as $accountId) {
                $net = $nets[$accountId] ?? null;
                $transfers[$key][$accountId] = null === $net
                    ? ['value' => null, 'reason' => 'TRANSFER_SCOPE_TOO_LARGE', 'asset' => null]
                    : ['value' => $net->value, 'reason' => $net->reason?->value, 'asset' => $net->asset];
            }
        }

        return $transfers;
    }

    /**
     * @param list<string> $categoryIds
     *
     * @return array{value: ?DecimalValue, reason: ?string}
     */
    public function metric(Goal $goal, AnnualMonth $month, array $categoryIds, GoalStatusInputs $inputs): array
    {
        if (MonthState::NO_DATA === $month->state || MonthState::FUTURE === $month->state) {
            return ['value' => null, 'reason' => $month->state->value];
        }
        if (in_array($goal->metric, [GoalMetric::TOTAL_INCOME, GoalMetric::TOTAL_SPENDING], true) && false === $month->figures?->hasTransactions) {
            return ['value' => null, 'reason' => 'NO_DATA'];
        }
        if (GoalMetric::ACCOUNT_CONTRIBUTIONS === $goal->metric) {
            $net = $inputs->accountTransfers[$month->month->key()][$goal->scopeRef ?? ''] ?? throw new \LogicException('An account goal status reads its preloaded transfer net.');
            if (null !== $net['asset'] && !$net['asset']->equals($goal->assetCode)) {
                return ['value' => null, 'reason' => 'MIXED_ASSETS'];
            }

            return ['value' => $net['value'], 'reason' => $net['reason']];
        }
        if (GoalMetric::CATEGORY_SPENDING === $goal->metric) {
            $budgetExpenses = $month->figures?->cells['budgetExpenses'] ?? null;
            if (null === $budgetExpenses) {
                return ['value' => null, 'reason' => 'MISSING_MONTH_VALUE'];
            }
            if (null === $budgetExpenses->value) {
                return ['value' => null, 'reason' => $budgetExpenses->reason ?? 'MISSING_MONTH_VALUE'];
            }
            $included = array_flip($month->figures->budgetExpenseCategoryIds);
            $values = [];
            foreach ($categoryIds as $categoryId) {
                if (!isset($included[$categoryId])) {
                    continue;
                }
                $category = $month->figures?->cells[MonthFigures::OWN_CATEGORY_PREFIX.$categoryId] ?? null;
                if (null === $category) {
                    return ['value' => null, 'reason' => 'MISSING_MONTH_VALUE'];
                }
                if (null === $category->value) {
                    if ('NO_MOVEMENTS' === $category->reason) {
                        continue;
                    }

                    return ['value' => null, 'reason' => $category->reason ?? 'MISSING_MONTH_VALUE'];
                }
                if (!self::inCurrency($category, $goal)) {
                    return ['value' => null, 'reason' => 'MIXED_ASSETS'];
                }
                $values[] = DecimalValue::fromString($category->value);
            }

            return ['value' => ExactDecimal::sum(...$values), 'reason' => null];
        }
        $cell = match ($goal->metric) {
            GoalMetric::TOTAL_INCOME => 'cashIncome',
            GoalMetric::TOTAL_SPENDING => 'budgetExpenses',
            GoalMetric::NET_SAVINGS_TRANSFERS => 'netSavingsTransfers',
            GoalMetric::BUDGET_SURPLUS => 'budgetSurplus',
            GoalMetric::SAVINGS_RATE => 'cashSavingsRate',
            GoalMetric::NET_SAVINGS_RATE => 'netSavingsRate',
            GoalMetric::NET_WORTH, GoalMetric::ACCOUNT_BALANCE => throw new \LogicException('Stock metrics use dated valuations.'),
            GoalMetric::AXIS_SPENDING => 'axis:'.$goal->scopeRef,
        };
        $metric = $month->figures?->cells[$cell] ?? null;
        if (null === $metric) {
            return ['value' => null, 'reason' => 'MISSING_MONTH_VALUE'];
        }
        if (null === $metric->value) {
            return ['value' => null, 'reason' => $metric->reason ?? 'MISSING_MONTH_VALUE'];
        }

        if (!self::inCurrency($metric, $goal)) {
            return ['value' => null, 'reason' => 'MIXED_ASSETS'];
        }

        return ['value' => DecimalValue::fromString($metric->value), 'reason' => $metric->reason];
    }

    /** A figure is only summed in the goal's currency when its own asset is that currency. */
    private static function inCurrency(MonthlyMetricView $metric, Goal $goal): bool
    {
        return null === $metric->assetCode || AssetCode::fromString($metric->assetCode)->equals($goal->assetCode);
    }

    /** @return list<string> */
    public function categoryBranch(Goal $goal, GoalStatusInputs $inputs): array
    {
        if (GoalMetric::CATEGORY_SPENDING !== $goal->metric || null === $goal->scopeRef || null === $inputs->categoryFacts) {
            return null === $goal->scopeRef ? [] : [$goal->scopeRef];
        }
        $facts = $inputs->categoryFacts;
        $byId = [];
        foreach ($facts as $fact) {
            $byId[$fact->id] = $fact;
        }
        $ids = [];
        foreach ($facts as $fact) {
            $cursor = $fact;
            $guard = 0;
            while ($guard++ < Category::MAX_TREE_DEPTH) {
                if ($cursor->id === $goal->scopeRef) {
                    $ids[] = $fact->id;
                    break;
                }
                if (null === $cursor->parentId || !isset($byId[$cursor->parentId])) {
                    break;
                }
                $cursor = $byId[$cursor->parentId];
            }
        }
        sort($ids, SORT_STRING);

        return $ids;
    }

    /** @param list<MonthValue> $values */
    public static function policy(array $values): GoalStatusMetricPolicyView
    {
        $versions = array_values(array_unique(array_filter(array_map(static fn (MonthValue $value): ?int => $value->policyVersion, $values), static fn (?int $version): bool => null !== $version)));

        return self::policyFromVersions($versions);
    }

    /** @param list<int> $versions */
    public static function policyFromVersions(array $versions): GoalStatusMetricPolicyView
    {
        $versions = array_values(array_unique($versions));
        sort($versions);

        return new GoalStatusMetricPolicyView(count($versions) > 1 ? 'MIXED' : ([] === $versions ? 'NONE' : 'SINGLE'), 1 === count($versions) ? $versions[0] : null, $versions);
    }
}
