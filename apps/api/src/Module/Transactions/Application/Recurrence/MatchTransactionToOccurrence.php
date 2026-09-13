<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Recurrence;

use App\Module\Transactions\Domain\Recurrence\OccurrenceMatcher;
use App\Module\Transactions\Domain\Recurrence\TransactionRecurrenceOccurrenceRepository;
use App\Module\Transactions\Domain\Recurrence\TransactionRecurrenceRepository;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionNature;

/** Reconciles one mutated real movement with the forecast, inside its caller's transaction. */
final readonly class MatchTransactionToOccurrence
{
    public function __construct(
        private TransactionRecurrenceRepository $recurrences,
        private TransactionRecurrenceOccurrenceRepository $occurrences,
        private OccurrenceGenerator $generator,
        private WorkspaceCalendar $calendar,
    ) {
    }

    public function __invoke(Transaction $transaction): void
    {
        $affected = [];
        $existing = $this->occurrences->findByMatchedTransaction($transaction->workspace, $transaction->id);
        if (null !== $existing) {
            $recurrence = $this->recurrences->find($transaction->workspace, $existing->recurrenceId);
            $stillFits = null !== $recurrence && null === $recurrence->archivedAt
                && $recurrence->accountId === $transaction->accountId
                && $this->eligible($transaction)
                && OccurrenceMatcher::fits($existing, $transaction->amount, $transaction->bookedOn);
            if ($stillFits) {
                return;
            }
            $this->occurrences->release($transaction->workspace, $existing->id);
            if (null !== $recurrence) {
                $affected[$recurrence->id] = $recurrence;
            }
        }

        if ($this->eligible($transaction)) {
            $from = $transaction->bookedOn->modify(sprintf('-%d days', OccurrenceMatcher::MATCH_WINDOW_DAYS));
            $to = $transaction->bookedOn->modify(sprintf('+%d days', OccurrenceMatcher::MATCH_WINDOW_DAYS));
            $match = OccurrenceMatcher::select(
                $this->occurrences->unmatchedNear($transaction->workspace, $transaction->accountId, $from, $to),
                $transaction->amount,
                $transaction->bookedOn,
            );
            if (null !== $match && $this->occurrences->claim(
                $transaction->workspace, $match->id, $transaction->id, $this->calendar->now(),
            )) {
                $recurrence = $this->recurrences->find($transaction->workspace, $match->recurrenceId);
                if (null !== $recurrence) {
                    $affected[$recurrence->id] = $recurrence;
                }
            }
        }

        foreach ($affected as $recurrence) {
            $this->recurrences->advanceNextExpectedOn(
                $transaction->workspace,
                $recurrence->id,
                $this->generator->pointer($recurrence, $this->calendar->today()),
            );
        }
    }

    private function eligible(Transaction $transaction): bool
    {
        return !$transaction->state->isTerminal()
            && in_array($transaction->nature, [TransactionNature::INCOME, TransactionNature::EXPENSE, TransactionNature::FEE], true);
    }
}
