<?php

declare(strict_types=1);

namespace App\Module\Budget\Application;

use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Budget\Domain\BudgetIncomeCalculator;
use App\Module\Budget\Domain\BudgetPeriod;
use App\Module\Foundation\Application\WorkspaceTimezoneReader;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reporting\Domain\MonthlyMetric;
use App\Module\Reporting\Domain\MonthlyMovement;
use App\Module\Reporting\Domain\MonthlyMovementKind;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionFilters;
use App\Module\Transactions\Domain\TransactionRepository;
use App\Module\Transactions\Domain\TransactionState;

/**
 * The "period cash income" a ratio target resolves against, for either a
 * MONTH or a YEAR budget period. It extends RPT-001's own definition to an
 * arbitrary bounded date range rather than copying its `CalendarMonth`-only
 * reads: {@see AccountRepository::listOpenDuring()} and
 * {@see TransactionRepository::search()} already accept a plain date range,
 * so a year is read exactly like a month, only over twelve times the span.
 */
final readonly class ReadPeriodCashIncome
{
    public const int MAX_ACCOUNTS = 500;
    public const int MAX_TRANSACTIONS = 6000;

    public function __construct(
        private AccountRepository $accounts,
        private TransactionRepository $transactions,
        private WorkspaceTimezoneReader $timezones,
    ) {
    }

    public function __invoke(WorkspaceScope $workspace, BudgetPeriod $period): MonthlyMetric
    {
        $first = $period->firstDay();
        $last = $period->lastDay();
        $timezone = new \DateTimeZone($this->timezones->timezone($workspace));

        $accounts = $this->accounts->listOpenDuring($workspace, $first, $last, $timezone, self::MAX_ACCOUNTS + 1);
        if (count($accounts) > self::MAX_ACCOUNTS) {
            throw new BudgetIncomeScopeTooLarge('A budget period income reads at most 500 accounts.');
        }

        $rows = $this->transactions->search(
            $workspace,
            new TransactionFilters(from: $first, to: $last, states: [TransactionState::BOOKED]),
            self::MAX_TRANSACTIONS + 1,
            null,
        );
        if (count($rows) > self::MAX_TRANSACTIONS) {
            throw new BudgetIncomeScopeTooLarge('A budget period income reads at most 6000 transactions.');
        }

        $movements = array_map(
            static fn (Transaction $transaction): MonthlyMovement => new MonthlyMovement(
                $transaction->amount->value,
                $transaction->amount->asset,
                MonthlyMovementKind::from($transaction->nature->value),
                [],
                false,
            ),
            $rows,
        );

        return BudgetIncomeCalculator::sumIncome(
            array_map(static fn (Account $account): string => $account->assetCode->toString(), $accounts),
            $movements,
        );
    }
}
