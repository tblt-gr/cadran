<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Accounts\Application\MonthlyAccountFact;
use App\Module\Accounts\Application\NetWorthScopeTooLarge;
use App\Module\Accounts\Application\NetWorthView;
use App\Module\Accounts\Application\ReadMonthlyAccountFacts;
use App\Module\Accounts\Application\ReadNetWorth;
use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Accounts\Domain\InvalidCalendarMonth;
use App\Module\Categories\Application\BudgetCategoryScopeTooLarge;
use App\Module\Categories\Application\ReadBudgetCategoryFacts;
use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Reporting\Domain\MonthlyAxisExpenseCalculator;
use App\Module\Reporting\Domain\MonthlyMetric;
use App\Module\Reporting\Domain\MonthlyMovement;
use App\Module\Reporting\Domain\MonthlyMovementKind;
use App\Module\Reporting\Domain\MonthlyProjectionCalculator;
use App\Module\Reporting\Domain\MonthlyProjectionReason;
use App\Module\Reporting\Domain\MonthlyRecapWindow;
use App\Module\Reporting\Domain\MonthlySavingsTransfer;
use App\Module\Reporting\Domain\MonthlySavingsTransferCalculator;
use App\Module\Reporting\Domain\MonthlySplit;
use App\Module\Transactions\Application\MonthlyTransactionFact;
use App\Module\Transactions\Application\MonthlyTransactionScopeTooLarge;
use App\Module\Transactions\Application\MonthlyTransferPairFact;
use App\Module\Transactions\Application\ReadMonthlyTransactionFacts;
use App\Module\Transactions\Application\ReadMonthlyTransferPairs;

final readonly class ReadMonthlyProjection
{
    public function __construct(
        private CallerWorkspace $caller,
        private ReadMonthlyTransactionFacts $transactions,
        private ReadMonthlyTransferPairs $transferPairs,
        private ReadMonthlyAccountFacts $accounts,
        private ReadBudgetCategoryFacts $categories,
        private ReadNetWorth $netWorth,
        private WorkspaceCalendar $calendar,
        private ResolveMetricPolicy $metricPolicy,
    ) {
    }

    public function __invoke(string $requestedMonth): MonthlyProjectionView
    {
        try {
            $month = CalendarMonth::fromString($requestedMonth);
        } catch (InvalidCalendarMonth $exception) {
            throw new InvalidMonthlyProjectionQuery($exception->getMessage(), previous: $exception);
        }

        $workspace = $this->caller->resolve();
        $policy = ($this->metricPolicy)($workspace, $month);
        // N−1 is the last day of the preceding month, and an unfinished month
        // stops on the day the workspace is living in. See MonthlyRecapWindow.
        $window = MonthlyRecapWindow::of($month, $this->calendar->today());
        try {
            $transactionFacts = ($this->transactions)($workspace, $month);
            $transferPairFacts = ($this->transferPairs)($workspace, $month);
            $accountFacts = ($this->accounts)($workspace, $month, $window->currentAsOf);
            $categoryFacts = ($this->categories)($workspace);
            $netWorth = ($this->netWorth)(
                $window->currentAsOf->format('Y-m-d'),
                $window->previousAsOf->format('Y-m-d'),
            );
        } catch (MonthlyTransactionScopeTooLarge|BudgetCategoryScopeTooLarge|NetWorthScopeTooLarge $exception) {
            throw new MonthlyProjectionScopeTooLarge('The monthly projection scope exceeds its bounds.', previous: $exception);
        }

        $accountsById = [];
        foreach ($accountFacts->accounts as $account) {
            $accountsById[$account->id] = $account;
        }
        $toMovement = static fn (MonthlyTransactionFact $fact): MonthlyMovement => new MonthlyMovement(
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
        $movements = array_map($toMovement, $transactionFacts->booked);
        $pendingMovements = array_map($toMovement, $transactionFacts->pending);
        $categoryFlags = [];
        foreach ($categoryFacts as $category) {
            $categoryFlags[$category->id] = $category->budgetIncluded;
        }
        $ledgerEntries = MonthlyLedgerFacts::entries(array_values(array_filter(
            $transactionFacts->booked,
            static fn (MonthlyTransactionFact $fact): bool => !($policy->policy?->isExcluded($accountsById[$fact->accountId]->kind ?? null) ?? false),
        )));
        $categoryMetrics = [];
        foreach ($categoryFacts as $category) {
            $metric = null === $policy->policy
                ? new MonthlyRecapCategoryView($category->id, $category->label, $category->type, new MonthlyMetricView(null, null, MonthlyProjectionReason::UNKNOWN_METRIC_POLICY->value))
                : MonthlyRecapCategoryView::of($category, $ledgerEntries);
            if (null !== $category->archivedAt && [] === $metric->metric->sourceTransactionIds) {
                continue;
            }
            $categoryMetrics[] = $metric;
        }
        $accountAssets = array_map(
            static fn (MonthlyAccountFact $account): string => $account->assetCode,
            $accountFacts->accounts,
        );
        $metrics = MonthlyProjectionCalculator::compute(
            $accountAssets,
            $movements,
            $categoryFlags,
            $policy->policy,
            $pendingMovements,
        );
        $cashMovements = array_values(array_filter(
            $movements,
            static fn (MonthlyMovement $movement): bool => !($policy->policy?->isExcluded($movement->accountKind) ?? false),
        ));
        $axisMetrics = null === $policy->policy
            ? array_fill_keys(RecapAxes::all(), MonthlyMetric::missing(MonthlyProjectionReason::UNKNOWN_METRIC_POLICY))
            : MonthlyAxisExpenseCalculator::compute($accountAssets, $cashMovements, $categoryFlags, RecapAxes::all());
        $savingsDestinationById = [];
        foreach ($accountFacts->accounts as $account) {
            $savingsDestinationById[$account->id] = $account->savingsDestination;
        }
        $toTransfer = static fn (MonthlyTransferPairFact $pair): MonthlySavingsTransfer => new MonthlySavingsTransfer(
            $pair->transferId,
            $pair->sourceTransactionId,
            $pair->targetTransactionId,
            $pair->sourceAccountId,
            $pair->targetAccountId,
            $pair->sourceAmount,
            $pair->targetAmount,
            $pair->sourceAsset,
            $pair->targetAsset,
            $pair->sourceState,
            $pair->targetState,
            $pair->sourceBookedOn,
            $pair->targetBookedOn,
            $pair->voided,
        );
        $savingsMetrics = MonthlySavingsTransferCalculator::compute(
            $accountAssets,
            $savingsDestinationById,
            array_map($toTransfer, $transferPairFacts),
            $metrics->cashIncome,
        );
        $netWorthDeltaAccountIds = array_values(array_unique(array_merge(
            $netWorth->delta->previousSourceAccountIds,
            $netWorth->delta->currentSourceAccountIds,
        )));
        sort($netWorthDeltaAccountIds, SORT_STRING);

        return new MonthlyProjectionView(
            $month->key(),
            $month->firstDay()->format('Y-m-d'),
            $month->lastDay()->format('Y-m-d'),
            $window->previousAsOf->format('Y-m-d'),
            $window->currentAsOf->format('Y-m-d'),
            $window->provisional,
            self::state(count($transactionFacts->booked), $transactionFacts->pendingCount),
            self::quality($netWorth),
            $transactionFacts->pendingCount,
            MonthlyMetricView::fromMetric($metrics->cashIncome),
            MonthlyMetricView::fromMetric($metrics->nonCashBenefits),
            MonthlyMetricView::fromMetric($metrics->benefitSpending),
            MonthlyMetricView::fromMetric($metrics->budgetExpenses),
            MonthlyMetricView::fromMetric($metrics->uncategorizedExpenses),
            MonthlyMetricView::fromMetric($metrics->budgetSurplus),
            MonthlyMetricView::fromMetric($metrics->savingsTransfers),
            MonthlyMetricView::fromMetric($metrics->cashSavingsRate),
            MonthlyMetricView::fromMetric($savingsMetrics->savingsInflows),
            MonthlyMetricView::fromMetric($savingsMetrics->savingsWithdrawals),
            MonthlyMetricView::fromMetric($savingsMetrics->netSavingsTransfers),
            MonthlyMetricView::fromMetric($savingsMetrics->netSavingsRate),
            MonthlyMetricView::fromNetWorth($netWorth->delta->previousTotal, $netWorth->delta->previousReason, $netWorth->delta->previousSourceAccountIds),
            MonthlyMetricView::fromNetWorth($netWorth->total, $netWorth->reason, $netWorth->delta->currentSourceAccountIds),
            MonthlyMetricView::fromNetWorth($netWorth->delta->amount, $netWorth->delta->amountReason, $netWorthDeltaAccountIds),
            self::beginningState($netWorth->delta->previousTotal?->amount),
            array_map(MonthlyAccountView::of(...), $accountFacts->accounts),
            $accountFacts->reconciliationStatus,
            array_map(MonthlyMetricView::fromMetric(...), $axisMetrics),
            $categoryMetrics,
            $netWorth,
            $policy->reference(),
        );
    }

    private static function state(int $bookedCount, int $pendingCount): string
    {
        return match (true) {
            $pendingCount > 0 => 'PENDING',
            0 === $bookedCount => 'EMPTY',
            default => 'COMPLETE',
        };
    }

    private static function quality(NetWorthView $netWorth): string
    {
        return match (true) {
            'MISSING' === $netWorth->delta->previousQuality || 'MISSING' === $netWorth->quality => 'MISSING',
            'STALE' === $netWorth->delta->previousQuality || 'STALE' === $netWorth->quality => 'STALE',
            default => 'CURRENT',
        };
    }

    private static function beginningState(?string $value): string
    {
        if (null === $value) {
            return 'NON_CALCULABLE';
        }
        $decimal = DecimalValue::fromString($value);
        $comparison = $decimal->compareTo(DecimalValue::zero());

        return match (true) {
            $comparison < 0 => 'NEGATIVE',
            0 === $comparison => 'ZERO',
            default => 'POSITIVE',
        };
    }
}
