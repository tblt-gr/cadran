<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain\Reconciliation;

use App\Module\Foundation\Domain\WorkspaceScope;

interface ReconciliationRepository
{
    /**
     * The pending rows a human is choosing between for one reviewed movement.
     * Recorded once, when the movement is put under review.
     *
     * @param list<string> $candidateTransactionIds
     */
    public function recordCandidates(
        WorkspaceScope $workspace,
        string $transactionId,
        array $candidateTransactionIds,
        \DateTimeImmutable $at,
    ): void;

    /** @return list<string> in identifier order */
    public function candidateIds(WorkspaceScope $workspace, string $transactionId): array;

    /**
     * Batched for a transaction page.
     *
     * @param list<string> $transactionIds
     *
     * @return array<string, list<string>> keyed by reviewed transaction id
     */
    public function candidateIdsByTransactionIds(WorkspaceScope $workspace, array $transactionIds): array;

    /** Resolution drops the choice; the outcome is the link and the row states. */
    public function clearCandidates(WorkspaceScope $workspace, string $transactionId): void;

    /**
     * Drops one candidate id from every review that still names it, not only
     * the one being resolved. A row that just got booked is never a valid
     * candidate again, on any review, in any workspace but this one.
     */
    public function removeCandidateEverywhere(WorkspaceScope $workspace, string $candidateTransactionId): void;

    public function link(TransactionReconciliation $reconciliation): void;

    /**
     * Batched for a transaction page: what each voided reviewed row settled.
     *
     * @param list<string> $reviewedTransactionIds
     *
     * @return array<string, string> matched transaction id keyed by reviewed transaction id
     */
    public function matchedIdsByReviewedTransactionIds(WorkspaceScope $workspace, array $reviewedTransactionIds): array;

    public function findByReviewedTransactionId(WorkspaceScope $workspace, string $reviewedTransactionId): ?TransactionReconciliation;
}
