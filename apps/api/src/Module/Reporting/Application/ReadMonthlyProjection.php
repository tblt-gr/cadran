<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Accounts\Application\MonthlyAccountFact;
use App\Module\Accounts\Application\MonthlyAccountFacts;
use App\Module\Accounts\Application\NetWorthScopeTooLarge;
use App\Module\Accounts\Application\ReadMonthlyAccountFacts;
use App\Module\Accounts\Application\ReadNetWorth;
use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Accounts\Domain\InvalidCalendarMonth;
use App\Module\Categories\Application\BudgetCategoryScopeTooLarge;
use App\Module\Categories\Application\ReadBudgetCategoryFlags;
use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Reporting\Domain\MonthlyMetric;
use App\Module\Reporting\Domain\MonthlyMovement;
use App\Module\Reporting\Domain\MonthlyMovementKind;
use App\Module\Reporting\Domain\MonthlyProjectionCalculator;
use App\Module\Reporting\Domain\MonthlyProjectionReason;
use App\Module\Reporting\Domain\MonthlySplit;
use App\Module\Transactions\Application\MonthlyTransactionFact;
use App\Module\Transactions\Application\MonthlyTransactionScopeTooLarge;
use App\Module\Transactions\Application\ReadMonthlyTransactionFacts;

final readonly class ReadMonthlyProjection
{
    public function __construct(
        private CallerWorkspace $caller,
        private ReadMonthlyTransactionFacts $transactions,
        private ReadMonthlyAccountFacts $accounts,
        private ReadBudgetCategoryFlags $categories,
        private ReadNetWorth $netWorth,
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
        try {
            $transactionFacts = ($this->transactions)($workspace, $month);
            $accountFacts = ($this->accounts)($workspace, $month);
            $categoryFlags = ($this->categories)($workspace);
            $netWorth = ($this->netWorth)($month->lastDay()->format('Y-m-d'), $month->firstDay()->format('Y-m-d'));
        } catch (MonthlyTransactionScopeTooLarge|BudgetCategoryScopeTooLarge|NetWorthScopeTooLarge $exception) {
            throw new MonthlyProjectionScopeTooLarge('The monthly projection scope exceeds its bounds.', previous: $exception);
        }

        $accountsById = [];
        foreach ($accountFacts->accounts as $account) {
            $accountsById[$account->id] = $account;
        }
        $movements = array_map(
            static fn (MonthlyTransactionFact $fact): MonthlyMovement => new MonthlyMovement(
                $fact->amount,
                $fact->asset,
                MonthlyMovementKind::from($fact->nature),
                array_map(static fn ($split): MonthlySplit => new MonthlySplit($split->categoryId, $split->amount), $fact->splits),
                $accountsById[$fact->accountId]->savingsDestination ?? false,
            ),
            $transactionFacts->booked,
        );
        $metrics = MonthlyProjectionCalculator::compute(
            array_map(static fn (MonthlyAccountFact $account): string => $account->assetCode, $accountFacts->accounts),
            $movements,
            $categoryFlags,
        );

        return new MonthlyProjectionView(
            $month->key(),
            $month->firstDay()->format('Y-m-d'),
            $month->lastDay()->format('Y-m-d'),
            self::state(count($transactionFacts->booked), $transactionFacts->pendingCount),
            self::quality($accountFacts),
            $transactionFacts->pendingCount,
            MonthlyMetricView::fromMetric($metrics->cashIncome),
            MonthlyMetricView::fromMetric(MonthlyMetric::missing(MonthlyProjectionReason::MISSING_BENEFIT_SOURCE)),
            MonthlyMetricView::fromMetric($metrics->budgetExpenses),
            MonthlyMetricView::fromMetric($metrics->uncategorizedExpenses),
            MonthlyMetricView::fromMetric($metrics->budgetSurplus),
            MonthlyMetricView::fromMetric($metrics->savingsTransfers),
            MonthlyMetricView::fromMetric($metrics->cashSavingsRate),
            MonthlyMetricView::fromNetWorth($netWorth->delta->previousTotal, $netWorth->delta->previousReason),
            MonthlyMetricView::fromNetWorth($netWorth->total, $netWorth->reason),
            MonthlyMetricView::fromNetWorth($netWorth->delta->amount, $netWorth->delta->amountReason),
            self::beginningState($netWorth->delta->previousTotal?->amount),
            array_map(MonthlyAccountView::of(...), $accountFacts->accounts),
            $accountFacts->reconciliationStatus,
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

    private static function quality(MonthlyAccountFacts $facts): string
    {
        if ([] === $facts->accounts) {
            return 'MISSING';
        }
        foreach ($facts->accounts as $account) {
            if ('MISSING' === $account->beginning->quality || 'MISSING' === $account->end->quality) {
                return 'MISSING';
            }
        }
        foreach ($facts->accounts as $account) {
            if ('STALE' === $account->beginning->quality || 'STALE' === $account->end->quality) {
                return 'STALE';
            }
        }

        return 'CURRENT';
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
