<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Transactions\Domain\InvalidTransaction;
use App\Module\Transactions\Domain\TransactionRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * Replaces the whole split allocation of a transaction: `PUT .../splits`. The
 * amount, state and every other field stay untouched — only the categorisation
 * changes, so an empty allocation returns the transaction to the "to
 * categorise" queue instead of leaving a partial state at rest.
 */
final readonly class ReplaceTransactionSplits
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private TransactionRepository $transactions,
        private TransactionSplitInputParser $parser,
        private TransactionReferences $references,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
        private PresentTransaction $presentTransaction,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(string $id, ReplaceTransactionSplitsInput $input): TransactionView
    {
        $context = $this->caller->resolveContext();
        $inputs = $this->parser->parse($input->splits);

        return $this->transactionBoundary->transactional(function () use ($context, $inputs, $input, $id): TransactionView {
            // The lock precedes the exact-sum re-check below, so no concurrent
            // split rewrite on the same transaction can race this one.
            $current = $this->transactions->findForUpdate($context->workspace, $id);
            if (null === $current) {
                throw new TransactionNotFound();
            }
            if ($input->version !== $current->version) {
                throw new StaleTransactionVersion('The transaction changed concurrently.');
            }
            if ($current->state->isTerminal()) {
                throw new TransactionConflict('A voided or rejected transaction cannot be recategorised.');
            }

            $now = $this->clock->now();
            $existingByCategory = [];
            foreach ($current->splits as $split) {
                $existingByCategory[$split->categoryId] = $split;
            }
            try {
                $splits = $this->references->splits(
                    $context->workspace, $current->id, $inputs, $current->amount, $now, $existingByCategory,
                );
                $updated = $current->edit(
                    amount: $current->amount, nature: $current->nature, state: $current->state,
                    bookedOn: $current->bookedOn, valueOn: $current->valueOn, authorizedOn: $current->authorizedOn,
                    rawLabel: $current->rawLabel, counterparty: $current->counterparty, note: $current->note,
                    paymentMethod: $current->paymentMethod, mcc: $current->mcc, maskedCard: $current->maskedCard,
                    bankReference: $current->bankReference, splits: $splits, updatedAt: $now,
                    lastEditorId: $context->actorId,
                );
            } catch (InvalidTransaction $exception) {
                throw new InvalidTransactionInput('The requested split allocation is invalid.', previous: $exception);
            }
            if (!$this->transactions->update($updated, $current->version)) {
                throw new StaleTransactionVersion('The transaction changed concurrently.');
            }
            ($this->recordAuditEvent)(new AuditEventRecord(
                $context->workspace, $context->actorId, TransactionAuditEvents::UPDATED,
                TransactionAuditEvents::ENTITY, $updated->id,
                AuditDiff::change(TransactionAuditFingerprint::of($current), TransactionAuditFingerprint::of($updated)),
            ));

            return $this->presentTransaction->one($updated);
        });
    }
}
