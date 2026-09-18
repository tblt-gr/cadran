<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Reconciliation;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Transactions\Application\Recurrence\MatchTransactionToOccurrence;
use App\Module\Transactions\Application\StaleTransactionVersion;
use App\Module\Transactions\Application\TransactionAuditEvents;
use App\Module\Transactions\Application\TransactionAuditFingerprint;
use App\Module\Transactions\Application\TransactionConflict;
use App\Module\Transactions\Domain\DuplicateSourceReference;
use App\Module\Transactions\Domain\InvalidTransaction;
use App\Module\Transactions\Domain\Reconciliation\ReconciliationRepository;
use App\Module\Transactions\Domain\RefundRepository;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionRepository;
use App\Module\Transactions\Domain\TransactionSplit;
use App\Module\Transactions\Domain\TransactionState;
use App\Module\Transactions\Domain\TransferRepository;

/**
 * Books the pending row an incoming movement settles.
 *
 * Only what the movement itself observed crosses over — its exact amount and
 * the day it was booked. The row's own identity and everything a human added
 * to it (raw label, counterparty, note, categorisation) stays untouched: a
 * provider delivery is not allowed to overwrite the owner's work, and the
 * amount and date it does change are what the audit diff exposes.
 */
final readonly class SettlePendingTransaction
{
    public function __construct(
        private TransactionRepository $transactions,
        private TransferRepository $transfers,
        private RefundRepository $refunds,
        private ReconciliationRepository $reconciliations,
        private RecordAuditEvent $recordAuditEvent,
        private MatchTransactionToOccurrence $matchRecurrence,
    ) {
    }

    public function __invoke(
        Transaction $candidate,
        AssetAmount $amount,
        \DateTimeImmutable $bookedOn,
        \DateTimeImmutable $now,
        ?string $actorId,
        ?string $sourceRef = null,
    ): Transaction {
        if (TransactionState::PENDING !== $candidate->state) {
            throw new TransactionConflict('Only a pending transaction can settle an incoming movement.');
        }
        // The same guards UpdateTransaction enforces on a plain edit: none of
        // these rows may change amount or state outside their own operation,
        // whatever route a settlement is reached through (auto-match, a
        // stable external identifier, or a human's /reconcile resolution).
        if (null !== $this->transfers->findByLegTransactionId($candidate->workspace, $candidate->id)) {
            throw new TransactionConflict('A transfer leg can only be edited or voided through its transfer.');
        }
        if (null !== $this->refunds->findByRefundTransactionId($candidate->workspace, $candidate->id)) {
            throw new TransactionConflict('A linked refund must be voided and recreated instead of edited.');
        }
        if ($this->refunds->hasLiveRefund($candidate->workspace, $candidate->id)) {
            throw new TransactionConflict('An original with live refunds cannot be edited.');
        }

        try {
            // Resolving the review first is what lets the edit below change the
            // state; taking the incoming identifier is what makes the next
            // delivery of this movement recognisable without any scoring.
            $settled = $candidate->resolveReview()->adoptSourceRef($sourceRef)->edit(
                amount: $amount, nature: $candidate->nature, state: TransactionState::BOOKED,
                bookedOn: $bookedOn, valueOn: $candidate->valueOn, authorizedOn: $candidate->authorizedOn,
                rawLabel: $candidate->rawLabel, counterparty: $candidate->counterparty, note: $candidate->note,
                paymentMethod: $candidate->paymentMethod, mcc: $candidate->mcc, maskedCard: $candidate->maskedCard,
                bankReference: $candidate->bankReference, splits: self::reallocated($candidate, $amount), updatedAt: $now,
                lastEditorId: $actorId,
            );
        } catch (InvalidTransaction $exception) {
            throw new TransactionConflict('The incoming movement cannot settle this transaction.', previous: $exception);
        }
        try {
            $written = $this->transactions->update($settled, $candidate->version);
        } catch (DuplicateSourceReference $exception) {
            // A concurrent writer took the identifier between the lookup and
            // this write. The message never carries the identifier itself.
            throw new TransactionConflict('This external identifier is already recorded on the account.', previous: $exception);
        }
        if (!$written) {
            throw new StaleTransactionVersion('The transaction changed concurrently.');
        }
        // This row is booked now, so it is never a valid candidate again. Any
        // other review still holding its id would otherwise offer a human a
        // choice that is already gone, only to fail later with a generic
        // conflict once picked.
        $this->reconciliations->removeCandidateEverywhere($candidate->workspace, $candidate->id);
        ($this->recordAuditEvent)(new AuditEventRecord(
            $candidate->workspace, $actorId, TransactionAuditEvents::RECONCILED,
            TransactionAuditEvents::ENTITY, $settled->id,
            AuditDiff::change(TransactionAuditFingerprint::of($candidate), TransactionAuditFingerprint::changed($candidate, $settled)),
        ));
        ($this->matchRecurrence)($settled);

        return $settled;
    }

    /**
     * An allocation must sum exactly to its transaction, so a settlement that
     * changes the amount cannot keep the rows unchanged. A single-category
     * allocation follows the new amount, which keeps the owner's category. A
     * multi-category one cannot be rewritten without inventing a share nobody
     * chose, so the settlement is refused and the human re-allocates first.
     *
     * @return list<TransactionSplit>
     */
    private static function reallocated(Transaction $candidate, AssetAmount $amount): array
    {
        if ([] === $candidate->splits || 0 === $amount->value->compareTo($candidate->amount->value)) {
            return $candidate->splits;
        }
        if (1 !== count($candidate->splits)) {
            throw new TransactionConflict('A multi-category allocation must be restated before a settlement changes the amount.');
        }
        $split = $candidate->splits[0];

        return [new TransactionSplit(
            $split->id, $split->workspace, $split->transactionId, $split->categoryId, $amount,
            $split->analyticAxes, $split->note, $split->createdAt, $split->position, $split->origin, $split->ruleId,
        )];
    }
}
