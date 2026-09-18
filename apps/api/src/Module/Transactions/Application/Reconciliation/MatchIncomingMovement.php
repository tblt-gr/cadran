<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Reconciliation;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Application\TransactionConflict;
use App\Module\Transactions\Domain\Reconciliation\ReconciliationMatcher;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionRepository;
use App\Module\Transactions\Domain\TransactionState;

/**
 * Answers which pending row, if any, an incoming booked movement settles.
 *
 * A stable external identifier wins outright: when the provider tells us this
 * is the movement it already announced, no scoring is involved. Only without
 * one does the narrow amount-and-window rule run, and only when it singles out
 * exactly one row does anything settle automatically.
 */
final readonly class MatchIncomingMovement
{
    /**
     * Bounded like every other scan in this module: a workspace with more
     * pending rows than this on one account has a reconciliation backlog
     * problem, not a matching problem.
     */
    private const int MAX_CANDIDATES_SCANNED = 200;

    public function __construct(private TransactionRepository $transactions)
    {
    }

    public function __invoke(
        WorkspaceScope $workspace,
        string $accountId,
        ?string $sourceRef,
        AssetAmount $amount,
        \DateTimeImmutable $bookedOn,
    ): IncomingMovementMatch {
        if (null !== $sourceRef) {
            // Across every live state, not only the pending ones: a movement
            // already settled or already waiting for a human is one the
            // workspace holds, and delivering it again records nothing new.
            $claimed = $this->transactions->findBySourceRef($workspace, $accountId, $sourceRef, true);
            if (null !== $claimed) {
                if (TransactionState::PENDING !== $claimed->state || null !== $claimed->reviewReason) {
                    throw new TransactionConflict('This movement is already recorded under the same external identifier.');
                }

                return IncomingMovementMatch::settling($claimed);
            }
        }

        // Locked: whatever this match concludes is written in the same
        // transaction, so no concurrent writer can settle the same row twice.
        // Rows already waiting for a human, transfer legs, refunds and
        // originals with a live refund are excluded by the repository itself,
        // ahead of the scan cap, so none of them can hide a real candidate
        // behind an unrelated backlog nor surface as one to settle.
        $pending = $this->transactions->listPendingByAccount(
            $workspace, $accountId, $amount, $bookedOn, ReconciliationMatcher::MATCH_WINDOW_DAYS, self::MAX_CANDIDATES_SCANNED, true,
        );

        $candidates = ReconciliationMatcher::candidates($pending, $amount, $bookedOn);
        $selected = ReconciliationMatcher::select($pending, $amount, $bookedOn);
        if ($selected instanceof Transaction) {
            return IncomingMovementMatch::settling($selected);
        }

        return [] === $candidates ? IncomingMovementMatch::absent() : IncomingMovementMatch::ambiguous($candidates);
    }
}
