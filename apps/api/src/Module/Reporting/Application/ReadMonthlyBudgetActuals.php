<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Accounts\Application\MonthlyAccountFact;
use App\Module\Accounts\Application\ReadMonthlyAccountFacts;
use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Categories\Application\BudgetCategoryFact;
use App\Module\Categories\Application\ReadBudgetCategoryFacts;
use App\Module\Categories\Domain\Category;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reporting\Domain\MonthlyBudgetActual;
use App\Module\Reporting\Domain\MonthlyBudgetActualCalculator;
use App\Module\Reporting\Domain\MonthlyBudgetActualSource;
use App\Module\Reporting\Domain\MonthlyBudgetCashIncomeCalculator;
use App\Module\Reporting\Domain\MonthlyMovement;
use App\Module\Reporting\Domain\MonthlyMovementKind;
use App\Module\Reporting\Domain\MonthlyProjectionReason;
use App\Module\Reporting\Domain\MonthlySplit;
use App\Module\Transactions\Application\MonthlyTransactionFact;
use App\Module\Transactions\Application\ReadMonthlyTransactionFacts;

/** The Budget-facing, bounded application seam over RPT-001's source aggregate. */
final readonly class ReadMonthlyBudgetActuals
{
    public function __construct(
        private ReadMonthlyTransactionFacts $transactions,
        private ReadMonthlyAccountFacts $accounts,
        private ReadBudgetCategoryFacts $categories,
        private ResolveMetricPolicy $metricPolicy,
    ) {
    }

    /** @param list<MonthlyBudgetScope> $scopes */
    public function __invoke(
        WorkspaceScope $workspace,
        CalendarMonth $month,
        array $scopes,
        string $expectedAsset,
        bool $includeUpcoming = false,
    ): MonthlyBudgetActualsView {
        $transactionFacts = ($this->transactions)($workspace, $month, $includeUpcoming);
        $accountFacts = ($this->accounts)($workspace, $month);
        $categoryFacts = ($this->categories)($workspace);
        $policy = ($this->metricPolicy)($workspace, $month);

        $accountsById = [];
        foreach ($accountFacts->accounts as $account) {
            $accountsById[$account->id] = $account;
        }
        $allBooked = array_map(
            static fn (MonthlyTransactionFact $fact): MonthlyMovement => self::movement($fact, $accountsById),
            $transactionFacts->booked,
        );
        $inCashPerimeter = static fn (MonthlyMovement $movement): bool => !($policy->policy?->isExcluded($movement->accountKind) ?? false);
        $booked = array_values(array_filter($allBooked, $inCashPerimeter));
        $pending = array_values(array_filter(array_map(
            static fn (MonthlyTransactionFact $fact): MonthlyMovement => self::movement($fact, $accountsById),
            $transactionFacts->pending,
        ), $inCashPerimeter));
        $allPlanned = array_map(
            static fn (MonthlyTransactionFact $fact): MonthlyMovement => self::movement($fact, $accountsById),
            $transactionFacts->upcoming,
        );
        $planned = array_values(array_filter($allPlanned, $inCashPerimeter));
        $bookedFactsById = [];
        foreach ($transactionFacts->bookedWithUpcoming() as $fact) {
            $bookedFactsById[$fact->id] = $fact;
        }
        $accountAssets = array_map(static fn (MonthlyAccountFact $account): string => $account->assetCode, $accountFacts->accounts);
        [$flags, $ancestors] = self::categoryMaps($categoryFacts);
        // Ratio targets resolve against the cash income of the month, planned income included when asked for.
        $cashIncome = MonthlyBudgetCashIncomeCalculator::compute($accountAssets, [...$allBooked, ...$allPlanned], $policy->policy);

        $actuals = [];
        foreach ($scopes as $scope) {
            $actual = null === $policy->policy
                ? new MonthlyBudgetActual(null, null, MonthlyProjectionReason::UNKNOWN_METRIC_POLICY, [], 0)
                : MonthlyBudgetActualCalculator::compute(
                    $booked,
                    $pending,
                    $flags,
                    $ancestors,
                    $scope->type,
                    $scope->id,
                    $expectedAsset,
                    [] !== $accountFacts->accounts,
                    $planned,
                );
            $actuals[$scope->key] = new MonthlyBudgetActualView(
                new MonthlyMetricView($actual->amount, $actual->asset?->toString(), $actual->reason?->value),
                array_map(
                    static function (MonthlyBudgetActualSource $source) use ($bookedFactsById): MonthlyBudgetSourceView {
                        $fact = $bookedFactsById[$source->transactionId]
                            ?? throw new \LogicException('A budget source has no matching monthly transaction fact.');

                        return new MonthlyBudgetSourceView(
                            $source->transactionId,
                            $fact->bookedOn,
                            $fact->rawLabel,
                            $source->amount,
                            $source->asset->toString(),
                            $source->upcoming,
                        );
                    },
                    $actual->sources,
                ),
                $actual->pendingCount,
                $actual->upcomingAmount,
                $actual->upcomingCount,
            );
        }

        return new MonthlyBudgetActualsView(new MonthlyMetricView(
            $cashIncome->amount,
            $cashIncome->asset?->toString(),
            $cashIncome->reason?->value,
        ), $actuals, $policy->reference());
    }

    /** @param array<string, MonthlyAccountFact> $accountsById */
    private static function movement(MonthlyTransactionFact $fact, array $accountsById): MonthlyMovement
    {
        return new MonthlyMovement(
            $fact->amount,
            $fact->asset,
            MonthlyMovementKind::from($fact->nature),
            array_map(static fn ($split): MonthlySplit => new MonthlySplit(
                $split->categoryId,
                $split->amount,
                $split->analyticAxes,
            ), $fact->splits),
            $accountsById[$fact->accountId]->savingsDestination ?? false,
            $fact->id,
            $accountsById[$fact->accountId]->kind ?? null,
        );
    }

    /**
     * @param list<BudgetCategoryFact> $facts
     *
     * @return array{array<string, bool>, array<string, list<string>>}
     */
    private static function categoryMaps(array $facts): array
    {
        $byId = [];
        $flags = [];
        foreach ($facts as $fact) {
            $byId[$fact->id] = $fact;
            $flags[$fact->id] = $fact->budgetIncluded;
        }

        $ancestors = [];
        foreach ($facts as $fact) {
            $ancestors[$fact->id] = [];
            $cursor = $fact->parentId;
            $guard = 0;
            while (null !== $cursor && isset($byId[$cursor]) && $guard < Category::MAX_TREE_DEPTH) {
                $ancestors[$fact->id][] = $cursor;
                $cursor = $byId[$cursor]->parentId;
                ++$guard;
            }
        }

        return [$flags, $ancestors];
    }
}
