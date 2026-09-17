<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Transactions\Application\Categorization\CategorizationWriteLock;
use App\Module\Transactions\Application\Recurrence\MatchTransactionToOccurrence;
use App\Module\Transactions\Application\Recurrence\WorkspaceCalendar;
use App\Module\Transactions\Domain\InvalidTransaction;
use App\Module\Transactions\Domain\RefundRepository;
use App\Module\Transactions\Domain\TransactionRepository;
use App\Module\Transactions\Domain\TransactionSplit;
use App\Module\Transactions\Domain\TransferRepository;
use Symfony\Component\Clock\ClockInterface;

final readonly class UpdateTransaction
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private TransactionRepository $transactions,
        private RefundRepository $refunds,
        private TransferRepository $transfers,
        private TransactionInputParser $parser,
        private TransactionSplitInputParser $splitParser,
        private TransactionReferences $references,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
        private PresentTransaction $presentTransaction,
        private ClockInterface $clock,
        private CategorizationWriteLock $categorizationWriteLock,
        private MatchTransactionToOccurrence $matchRecurrence,
        private WorkspaceCalendar $calendar,
    ) {
    }

    public function __invoke(string $id, UpdateTransactionInput $input): TransactionView
    {
        $context = $this->caller->resolveContext();
        // Checked before parsing: a transfer leg's own nature (TRANSFER) would
        // otherwise always fail standalone parsing first, masking the more
        // specific "use the transfer" refusal behind a generic input error.
        $transfer = $this->transfers->findByLegTransactionId($context->workspace, $id);
        if (null !== $transfer) {
            throw new TransactionBelongsToTransfer($transfer->id);
        }
        $refund = $this->refunds->findByRefundTransactionId($context->workspace, $id);
        if (null !== $refund) {
            throw new TransactionBelongsToRefund($refund->originalTransactionId);
        }
        $draft = $this->parser->parse(
            $input->amount, $input->nature, $input->state, $input->bookedOn, $input->valueOn,
            $input->authorizedOn, $input->rawLabel, $input->counterparty, $input->note,
            $input->paymentMethod, $input->mcc, $input->maskedCard, $input->bankReference,
        );

        return $this->transactionBoundary->transactional(function () use ($context, $draft, $input, $id): TransactionView {
            $this->categorizationWriteLock->acquire($context->workspace);
            // This lock precedes the in-use-case exact-sum check below, so no
            // concurrent split rewrite can invalidate what this edit verified.
            $current = $this->transactions->findForUpdate($context->workspace, $id);
            if (null === $current) {
                throw new TransactionNotFound();
            }
            if ($this->refunds->hasLiveRefund($context->workspace, $id)) {
                throw new TransactionHasRefunds('An original with live refunds cannot be edited.');
            }
            if ($input->version !== $current->version) {
                throw new StaleTransactionVersion('The transaction changed concurrently.');
            }
            if ($current->state->isTerminal()) {
                throw new TransactionConflict('A terminal transaction cannot be edited.');
            }
            if ($input->accountId !== $current->accountId) {
                throw new InvalidTransactionInput('The account is immutable.');
            }
            $now = $this->clock->now();
            $today = $this->calendar->today();
            $this->references->accountForExisting($context->workspace, $current->accountId, $draft, $today);
            if (null === $input->splits && count($current->splits) > 1) {
                // The categoryId shorthand always replaces the whole allocation with
                // at most one row; a transaction that already carries several would
                // have that allocation silently collapsed. The request must name the
                // allocation explicitly through `splits`, even to leave it unchanged.
                throw new InvalidTransactionInput('An existing multi-category allocation requires an explicit splits array.');
            }
            $existingByCategory = [];
            foreach ($current->splits as $existingSplit) {
                $existingByCategory[$existingSplit->categoryId] = $existingSplit;
            }

            try {
                $splits = null === $input->splits
                    ? self::wrap($this->references->split(
                        $context->workspace, $current->id, $input->categoryId, $draft, $now, $current->splits[0] ?? null,
                    ))
                    : $this->references->splits(
                        $context->workspace, $current->id, $this->splitParser->parse($input->splits), $draft->amount, $now, $existingByCategory,
                    );
                $updated = $current->edit(
                    amount: $draft->amount, nature: $draft->nature, state: $draft->state,
                    bookedOn: $draft->bookedOn, valueOn: $draft->valueOn, authorizedOn: $draft->authorizedOn,
                    rawLabel: $draft->rawLabel, counterparty: $draft->counterparty, note: $draft->note,
                    paymentMethod: $draft->paymentMethod,
                    mcc: $draft->mcc, maskedCard: $draft->maskedCard, bankReference: $draft->bankReference,
                    splits: $splits, updatedAt: $now, lastEditorId: $context->actorId,
                );
            } catch (InvalidTransaction $exception) {
                throw new InvalidTransactionInput('The requested transaction edit is invalid.', previous: $exception);
            }
            if (!$this->transactions->update($updated, $current->version)) {
                throw new StaleTransactionVersion('The transaction changed concurrently.');
            }
            ($this->recordAuditEvent)(new AuditEventRecord(
                $context->workspace, $context->actorId, TransactionAuditEvents::UPDATED,
                TransactionAuditEvents::ENTITY, $updated->id,
                AuditDiff::change(TransactionAuditFingerprint::of($current), TransactionAuditFingerprint::of($updated)),
            ));
            ($this->matchRecurrence)($updated);

            return $this->presentTransaction->one($updated);
        });
    }

    /** @return list<TransactionSplit> */
    private static function wrap(?TransactionSplit $split): array
    {
        return null === $split ? [] : [$split];
    }
}
