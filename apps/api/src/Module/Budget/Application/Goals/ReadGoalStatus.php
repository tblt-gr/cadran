<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Accounts\Domain\InvalidCalendarMonth;
use App\Module\Budget\Domain\Goals\Goal;
use App\Module\Budget\Domain\Goals\GoalDirection;
use App\Module\Budget\Domain\Goals\GoalMetric;
use App\Module\Budget\Domain\Goals\GoalPeriodicity;
use App\Module\Budget\Domain\Goals\GoalRepository;
use App\Module\Budget\Domain\Goals\GoalValueType;
use App\Module\Budget\Domain\Goals\GoalVersion;
use App\Module\Budget\Domain\Goals\GoalVersionRepository;
use App\Module\Budget\Domain\Goals\GoalVersionResolver;
use App\Module\Categories\Application\ReadBudgetCategoryFacts;
use App\Module\Categories\Domain\Category;
use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reporting\Application\AnnualMonth;
use App\Module\Reporting\Application\MonthFigures;
use App\Module\Reporting\Application\MonthlyMetricView;
use App\Module\Reporting\Domain\Aggregation\AggregateReason;
use App\Module\Reporting\Domain\Aggregation\IncompleteMonths;
use App\Module\Reporting\Domain\Aggregation\MonthState;
use App\Module\Reporting\Domain\Aggregation\MonthValue;
use App\Module\Reporting\Domain\Aggregation\ReportAggregator;
use App\Module\Transactions\Application\ReadMonthlyAccountTransferNet;

final readonly class ReadGoalStatus
{
    public function __construct(
        private CallerWorkspace $caller,
        private GoalRepository $goals,
        private GoalVersionRepository $versions,
        private GoalMonthSource $months,
        private ReadMonthlyAccountTransferNet $accountTransfers,
        private WorkspaceCalendar $calendar,
        private ?ResolveGoalScope $scope = null,
        private ?ReadBudgetCategoryFacts $categories = null,
    ) {
    }

    /**
     * Without a month, the status is read at the current workspace-local month of the current year
     * and at December of a past year; a future year has no calculable month.
     */
    public function __invoke(string $id, ?string $monthKey = null, ?int $year = null): GoalStatusView
    {
        $today = CalendarMonth::containing($this->calendar->today());
        if (null !== $year && ($year < 1900 || $year > $today->year + 5)) {
            throw new GoalInputError('invalid_goal_year', 'A goal status year lies from 1900 to five years ahead.');
        }
        if (null === $monthKey) {
            $month = self::defaultMonth($today, $year ?? $today->year);
        } else {
            try {
                $month = CalendarMonth::fromString($monthKey);
            } catch (InvalidCalendarMonth) {
                throw new GoalInputError('invalid_goal_month', 'A goal status month is written YYYY-MM.');
            }
            if (null !== $year && $month->year !== $year) {
                throw new GoalInputError('invalid_goal_query', 'A goal status month belongs to its selected year.');
            }
        }
        if (self::isFuture($month, $today)) {
            throw new GoalInputError('future_goal_month', 'A goal status cannot be calculated for a future month.');
        }

        $workspace = $this->caller->resolve();
        $goal = $this->goals->find($workspace, $id) ?? throw new GoalNotFound('This goal does not exist in this workspace.');
        $versions = $this->versions->listByGoal($workspace, $id, 50);

        return $this->forGoal($goal, $versions, $month, $this->inputs($workspace, $month, [$goal]));
    }

    /**
     * The month a status is read at when none is chosen: December of a past year, the current
     * month of the current year and January of a future year, which is itself a future month.
     */
    public static function defaultMonth(CalendarMonth $today, int $year): CalendarMonth
    {
        return match (true) {
            $year < $today->year => new CalendarMonth($year, 12),
            $year === $today->year => $today,
            default => new CalendarMonth($year, 1),
        };
    }

    public static function isFuture(CalendarMonth $month, CalendarMonth $today): bool
    {
        return self::ordinal($month) > self::ordinal($today);
    }

    /**
     * A future month has no real figure yet: its status names that month, the definition then in
     * effect and no amount, rather than borrowing the figures of another period.
     *
     * @param list<GoalVersion> $versions
     */
    public function future(Goal $goal, array $versions, CalendarMonth $month): GoalStatusView
    {
        return $this->notCalculable($goal, GoalVersionResolver::forMonth($versions, $month), $month, 'FUTURE_MONTH', 'EMPTY');
    }

    /**
     * A month the goal does not cover, or a definition this read does not evaluate yet, has no
     * figure: the status names the month and the definition then in effect, never a borrowed value.
     */
    private function notCalculable(Goal $goal, ?GoalVersion $version, CalendarMonth $month, string $reason, string $quality): GoalStatusView
    {
        $amount = GoalValueType::AMOUNT === $version?->valueType;

        return new GoalStatusView(
            $goal->id,
            $month->key(),
            $goal->periodicity->value,
            $goal->direction()->value,
            $version?->valueType->value,
            $version?->versionNo,
            GoalPeriodicity::YEARLY === $goal->periodicity ? $version?->scheduleMode() : null,
            false,
            self::policyFromVersions([]),
            $amount ? $version->targetAmount?->toString() : null,
            null,
            null,
            null,
            null,
            null,
            'NOT_CALCULABLE',
            null,
            null,
            $reason,
            $quality,
            [],
            $amount ? null : $version?->ratio?->toString(),
        );
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
    public function inputsBetween(WorkspaceScope $workspace, CalendarMonth $first, CalendarMonth $last, array $goals): GoalStatusInputs
    {
        if ([] === $goals) {
            return new GoalStatusInputs([], null);
        }
        $monthly = [] !== array_filter($goals, static fn (Goal $goal): bool => GoalPeriodicity::MONTHLY === $goal->periodicity);
        $categorySpending = [] !== array_filter($goals, static fn (Goal $goal): bool => GoalMetric::CATEGORY_SPENDING === $goal->metric && null !== $goal->scopeRef);
        // A monthly goal compares every month of the year with its own target, and a rolling share
        // reads the twelve months before each of them: the window opens before January.
        $start = $monthly ? ReadReferenceIncome::windowStart($first) : $first;
        $loaded = $this->months->between($workspace, $start, $last);
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
        for ($ordinal = self::ordinal($first); $ordinal <= self::ordinal($last); ++$ordinal) {
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
     * @param list<GoalVersion> $versions
     * @param ?GoalScopeView    $scope    the goal's resolved scope when the caller already holds it
     */
    public function forGoal(Goal $goal, array $versions, CalendarMonth $month, GoalStatusInputs $inputs, ?GoalScopeView $scope = null): GoalStatusView
    {
        $scope ??= null === $this->scope ? null : ($this->scope)($goal->workspace, $goal->scopeType, $goal->scopeRef, true);
        $archived = true === $scope?->archived;
        $categoryIds = $this->categoryBranch($goal, $inputs);
        $version = GoalVersionResolver::forMonth($versions, $month);
        if (!$goal->coversPeriod($month->key())) {
            return $this->notCalculable($goal, $version, $month, $month->key() < $goal->firstMonth() ? 'GOAL_NOT_STARTED' : 'GOAL_ENDED', 'EMPTY');
        }
        if (GoalPeriodicity::MONTHLY === $goal->periodicity) {
            return $this->monthly($goal, $versions, $month, $archived, $categoryIds, $inputs);
        }
        if (GoalValueType::AMOUNT !== $version?->valueType) {
            return $this->notCalculable($goal, $version, $month, null === $version ? 'GOAL_NOT_STARTED' : 'EVALUATION_NOT_AVAILABLE', 'EMPTY');
        }

        return $this->annual($goal, $versions, $month, $archived, $categoryIds, $inputs);
    }

    /**
     * @param list<GoalVersion> $versions
     * @param list<string>      $categoryIds
     */
    private function annual(Goal $goal, array $versions, CalendarMonth $selected, bool $archived, array $categoryIds, GoalStatusInputs $inputs): GoalStatusView
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
            $metric = $this->metric($goal, $item, $categoryIds, $inputs);
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
        $policy = self::policy($values);
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
    private function monthly(Goal $goal, array $versions, CalendarMonth $selected, bool $archived, array $categoryIds, GoalStatusInputs $inputs): GoalStatusView
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
        $policy = self::policyFromVersions($evaluation['policyVersions']);
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
        $metric = $this->metric($goal, $item, $categoryIds, $inputs);
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
            GoalMetric::NET_SAVINGS_TRANSFERS => 'netSavingsTransfers',
            GoalMetric::BUDGET_SURPLUS => 'budgetSurplus',
            GoalMetric::SAVINGS_RATE => 'cashSavingsRate',
            GoalMetric::NET_SAVINGS_RATE => 'netSavingsRate',
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
    private static function policy(array $values): GoalStatusMetricPolicyView
    {
        $versions = array_values(array_unique(array_filter(array_map(static fn (MonthValue $value): ?int => $value->policyVersion, $values), static fn (?int $version): bool => null !== $version)));

        return self::policyFromVersions($versions);
    }

    /** @param list<int> $versions */
    private static function policyFromVersions(array $versions): GoalStatusMetricPolicyView
    {
        $versions = array_values(array_unique($versions));
        sort($versions);

        return new GoalStatusMetricPolicyView(count($versions) > 1 ? 'MIXED' : ([] === $versions ? 'NONE' : 'SINGLE'), 1 === count($versions) ? $versions[0] : null, $versions);
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

    private static function ordinal(CalendarMonth $month): int
    {
        return $month->year * 12 + $month->month;
    }
}
