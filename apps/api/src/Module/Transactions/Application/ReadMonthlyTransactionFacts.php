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
    public const int MAX_TRANSACTIONS = 500;

    public function __construct(private TransactionRepository $transactions)
    {
    }

    public function __invoke(WorkspaceScope $workspace, CalendarMonth $month): MonthlyTransactionFacts
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
            throw new MonthlyTransactionScopeTooLarge('A monthly projection reads at most 500 transactions.');
        }

        $pending = array_values(array_filter(
            $rows,
            static fn (Transaction $transaction): bool => TransactionState::PENDING === $transaction->state,
        ));
        $booked = array_values(array_filter(
            $rows,
            static fn (Transaction $transaction): bool => TransactionState::BOOKED === $transaction->state,
        ));

        return new MonthlyTransactionFacts(
            array_map(self::fact(...), $booked),
            array_map(self::fact(...), $pending),
            count($pending),
        );
    }

    private static function fact(Transaction $transaction): MonthlyTransactionFact
    {
        return new MonthlyTransactionFact(
            $transaction->id,
            $transaction->accountId,
            $transaction->amount->value,
            $transaction->amount->asset,
            $transaction->nature->value,
            array_map(static fn ($split): MonthlyTransactionSplitFact => new MonthlyTransactionSplitFact(
                $split->categoryId,
                $split->amount->value,
                array_map(static fn ($axis): string => $axis->value, $split->analyticAxes),
            ), $transaction->splits),
        );
    }
}
