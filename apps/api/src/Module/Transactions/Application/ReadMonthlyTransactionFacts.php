<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionFilters;
use App\Module\Transactions\Domain\TransactionRepository;
use App\Module\Transactions\Domain\TransactionState;

/** A bounded, workspace-scoped read port consumed by reporting. */
final readonly class ReadMonthlyTransactionFacts
{
    /**
     * One month of a 100 000-transaction, 15-year history averages about 560 rows, and a busy month several times
     * that; 2000 bounds the read (and every read derived from a month) without refusing a realistic user.
     */
    public const int MAX_TRANSACTIONS = 2000;

    public function __construct(private TransactionRepository $transactions)
    {
    }

    /**
     * With $includeUpcoming the planned rows (pending, carrying the booking marker) are returned
     * apart in `upcoming` and leave `pending`; without it they stay pending like any other pending row.
     */
    public function __invoke(WorkspaceScope $workspace, CalendarMonth $month, bool $includeUpcoming = false): MonthlyTransactionFacts
    {
        $rows = $this->transactions->search(
            $workspace,
            new TransactionFilters(
                from: $month->firstDay(),
                to: $month->lastDay(),
                states: [TransactionState::BOOKED, TransactionState::PENDING],
            ),
            self::MAX_TRANSACTIONS + 1,
            null,
        );
        if (count($rows) > self::MAX_TRANSACTIONS) {
            throw new MonthlyTransactionScopeTooLarge(sprintf('A monthly projection reads at most %d transactions.', self::MAX_TRANSACTIONS));
        }

        $booked = [];
        $pending = [];
        $upcoming = [];
        foreach ($rows as $transaction) {
            $fact = self::fact($transaction);
            if (TransactionState::BOOKED === $transaction->state) {
                $booked[] = $fact;
            } elseif ($includeUpcoming && $fact->autoBook) {
                $upcoming[] = $fact;
            } else {
                $pending[] = $fact;
            }
        }

        return new MonthlyTransactionFacts($booked, $pending, count($pending), $upcoming);
    }

    private static function fact(Transaction $transaction): MonthlyTransactionFact
    {
        return new MonthlyTransactionFact(
            $transaction->id,
            $transaction->accountId,
            $transaction->amount->value,
            $transaction->amount->asset,
            $transaction->nature->value,
            $transaction->bookedOn->format('Y-m-d'),
            $transaction->displayLabel(),
            array_map(static fn ($split): MonthlyTransactionSplitFact => new MonthlyTransactionSplitFact(
                $split->categoryId,
                $split->amount->value,
                array_map(static fn ($axis): string => $axis->value, $split->analyticAxes),
            ), $transaction->splits),
            $transaction->autoBook,
        );
    }
}
