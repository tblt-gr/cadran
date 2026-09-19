<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Reconciliation;

use App\Module\Accounts\Application\AssertPeriodOpen;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Domain\UuidGenerator;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Application\Categorization\CategorizationWriteLock;
use App\Module\Transactions\Application\PresentTransaction;
use App\Module\Transactions\Application\Recurrence\MatchTransactionToOccurrence;
use App\Module\Transactions\Application\StaleTransactionVersion;
use App\Module\Transactions\Application\TransactionAuditEvents;
use App\Module\Transactions\Application\TransactionAuditFingerprint;
use App\Module\Transactions\Application\TransactionConflict;
use App\Module\Transactions\Application\TransactionNotFound;
use App\Module\Transactions\Application\TransactionView;
use App\Module\Transactions\Domain\InvalidTransaction;
use App\Module\Transactions\Domain\Reconciliation\ReconciliationRepository;
use App\Module\Transactions\Domain\Reconciliation\TransactionReconciliation;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionRepository;
use App\Module\Transactions\Domain\TransactionState;
use Symfony\Component\Clock\ClockInterface;

/**
 * Resolves one movement held under reconciliation review.
 *
 * Naming a match means "these two rows are the same real movement": the named
 * pending row is booked with the reviewed movement's amount and date, and the
 * reviewed row is voided with a durable link back to it, the way a refund
 * keeps its original rather than rewriting it. Naming nothing means the
 * reviewed movement is genuinely new and books on its own.
 *
 * Every row the resolution can touch is locked before anything is read for a
 * decision, in identifier order, so two reviewers cannot turn one ambiguity
 * into two contradictory outcomes. This is the single entry point of the
 * operation, so the period guard `CLS-001` introduces has one place to sit.
 */
final readonly class ReconcileTransaction
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private TransactionRepository $transactions,
        private ReconciliationRepository $reconciliations,
        private SettlePendingTransaction $settle,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
        private PresentTransaction $presentTransaction,
        private ClockInterface $clock,
        private UuidGenerator $uuidGenerator,
        private CategorizationWriteLock $categorizationWriteLock,
        private MatchTransactionToOccurrence $matchRecurrence,
        private AssertPeriodOpen $assertPeriodOpen,
    ) {
    }

    public function __invoke(string $id, int $version, ?string $matchedTransactionId): TransactionView
    {
        $context = $this->caller->resolveContext();

        return $this->transactionBoundary->transactional(function () use ($context, $id, $version, $matchedTransactionId): TransactionView {
            $this->categorizationWriteLock->acquire($context->workspace);
            $now = $this->clock->now();
            $candidateIds = $this->reconciliations->candidateIds($context->workspace, $id);
            $locked = $this->lock($context->workspace, $id, $candidateIds, $matchedTransactionId);

            $reviewed = $locked[$id] ?? throw new TransactionNotFound();
            ($this->assertPeriodOpen)($context->workspace, $reviewed->bookedOn);
            if ($version !== $reviewed->version) {
                throw new StaleTransactionVersion('The transaction changed concurrently.');
            }
            if (TransactionState::PENDING !== $reviewed->state || null === $reviewed->reviewReason) {
                throw new TransactionConflict('Only a transaction held for reconciliation review can be reconciled.');
            }

            $resolved = null === $matchedTransactionId
                ? $this->bookTheReviewedMovement($reviewed, $now, $context->actorId)
                : $this->settleTheNamedCandidate($reviewed, $locked[$matchedTransactionId] ?? null, $now, $context->actorId);
            $this->reconciliations->clearCandidates($context->workspace, $id);

            return $this->presentTransaction->one($resolved);
        });
    }

    /**
     * Locks the reviewed row and every row the resolution may name, in
     * identifier order: a fixed order is what keeps two concurrent reviews
     * from deadlocking on each other's rows.
     *
     * @param list<string> $candidateIds
     *
     * @return array<string, Transaction>
     */
    private function lock(WorkspaceScope $workspace, string $id, array $candidateIds, ?string $matchedTransactionId): array
    {
        $ids = [$id, ...$candidateIds];
        if (null !== $matchedTransactionId) {
            $ids[] = $matchedTransactionId;
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        $locked = [];
        foreach ($ids as $lockedId) {
            $row = $this->transactions->findForUpdate($workspace, $lockedId);
            if (null !== $row) {
                $locked[$lockedId] = $row;
            }
        }

        return $locked;
    }

    private function bookTheReviewedMovement(Transaction $reviewed, \DateTimeImmutable $now, ?string $actorId): Transaction
    {
        return ($this->settle)($reviewed, $reviewed->amount, $reviewed->bookedOn, $now, $actorId);
    }

    private function settleTheNamedCandidate(Transaction $reviewed, ?Transaction $candidate, \DateTimeImmutable $now, ?string $actorId): Transaction
    {
        if (null === $candidate) {
            // Another workspace's row, or none at all: the same answer, so a
            // caller cannot probe the existence of a foreign transaction.
            throw new TransactionNotFound();
        }
        if ($candidate->id === $reviewed->id) {
            throw new TransactionConflict('A reviewed movement cannot settle itself; omit the match instead.');
        }
        if ($candidate->accountId !== $reviewed->accountId) {
            throw new TransactionConflict('A match must sit on the same account as the reviewed movement.');
        }
        if (null !== $candidate->reviewReason) {
            // Settling one review with another would book a movement whose own
            // ambiguity nobody resolved, and leave its candidate list behind.
            throw new TransactionConflict('A movement waiting for its own review cannot settle another one.');
        }

        try {
            // Voided first, and stripped of its identifier: that identifier is
            // unique per account whatever the state, so the settled row can
            // only take it once this row has given it up.
            $voided = $reviewed->releaseSourceRef()->void($now, $actorId);
        } catch (InvalidTransaction $exception) {
            throw new TransactionConflict('The reviewed transaction cannot be voided.', previous: $exception);
        }
        if (!$this->transactions->update($voided, $reviewed->version)) {
            throw new StaleTransactionVersion('The transaction changed concurrently.');
        }
        $settled = ($this->settle)($candidate, $reviewed->amount, $reviewed->bookedOn, $now, $actorId, $reviewed->sourceRef);
        $this->reconciliations->link(new TransactionReconciliation(
            $this->uuidGenerator->generate(), $reviewed->workspace, $reviewed->id, $settled->id, $now,
        ));
        ($this->recordAuditEvent)(new AuditEventRecord(
            $reviewed->workspace, $actorId, TransactionAuditEvents::VOIDED,
            TransactionAuditEvents::ENTITY, $voided->id,
            AuditDiff::change(TransactionAuditFingerprint::of($reviewed), TransactionAuditFingerprint::of($voided)),
        ));
        ($this->matchRecurrence)($voided);

        return $settled;
    }
}
