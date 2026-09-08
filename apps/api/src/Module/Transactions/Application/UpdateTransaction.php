<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Catalog\Domain\BusinessDay;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Transactions\Domain\InvalidTransaction;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionRepository;
use Symfony\Component\Clock\ClockInterface;

final readonly class UpdateTransaction
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private TransactionRepository $transactions,
        private TransactionInputParser $parser,
        private TransactionReferences $references,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(string $id, UpdateTransactionInput $input): TransactionView
    {
        $context = $this->caller->resolveContext();
        $draft = $this->parser->parse(
            $input->amount, $input->nature, $input->state, $input->bookedOn, $input->valueOn,
            $input->authorizedOn, $input->rawLabel, $input->counterparty, $input->note,
            $input->paymentMethod, $input->mcc, $input->maskedCard, $input->bankReference,
        );

        return $this->transactionBoundary->transactional(function () use ($context, $draft, $input, $id): TransactionView {
            // This lock precedes the in-use-case exact-sum check below, so no
            // concurrent split rewrite can invalidate what this edit verified.
            $current = $this->transactions->findForUpdate($context->workspace, $id);
            if (null === $current) {
                throw new TransactionNotFound();
            }
            if ($input->version !== $current->version) {
                throw new StaleTransactionVersion('The transaction changed concurrently.');
            }
            if ($current->state->isTerminal()) {
                throw new TransactionConflict('A terminal transaction cannot be edited.');
            }
            if ($input->accountId !== $current->accountId || $input->rawLabel !== $current->rawLabel) {
                throw new InvalidTransactionInput('The account and raw label are immutable.');
            }
            $now = $this->clock->now();
            $today = BusinessDay::fromIsoDate($now->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y-m-d'))->date;
            $this->references->accountForExisting($context->workspace, $current->accountId, $draft, $today);
            $existingSplitId = $current->splits[0]->id ?? null;
            $split = $this->references->split(
                $context->workspace, $current->id, $input->categoryId, $draft, $now, $existingSplitId,
            );

            try {
                $updated = $current->edit(
                    amount: $draft->amount, nature: $draft->nature, state: $draft->state,
                    bookedOn: $draft->bookedOn, valueOn: $draft->valueOn, authorizedOn: $draft->authorizedOn,
                    counterparty: $draft->counterparty, note: $draft->note, paymentMethod: $draft->paymentMethod,
                    mcc: $draft->mcc, maskedCard: $draft->maskedCard, bankReference: $draft->bankReference,
                    splits: null === $split ? [] : [$split], updatedAt: $now, lastEditorId: $context->actorId,
                );
            } catch (InvalidTransaction $exception) {
                throw new InvalidTransactionInput('The requested transaction edit is invalid.', previous: $exception);
            }
            self::assertSingleFullSplit($updated);
            if (!$this->transactions->update($updated, $current->version)) {
                throw new StaleTransactionVersion('The transaction changed concurrently.');
            }
            ($this->recordAuditEvent)(new AuditEventRecord(
                $context->workspace, $context->actorId, TransactionAuditEvents::UPDATED,
                TransactionAuditEvents::ENTITY, $updated->id,
                AuditDiff::change(TransactionAuditFingerprint::of($current), TransactionAuditFingerprint::of($updated)),
            ));

            return TransactionView::fromTransaction($updated);
        });
    }

    private static function assertSingleFullSplit(Transaction $transaction): void
    {
        if (count($transaction->splits) > 1) {
            throw new InvalidTransactionInput('A transaction carries at most one split in this release.');
        }
        if ([] !== $transaction->splits
            && 0 !== $transaction->splits[0]->amount->value->compareTo($transaction->amount->value)) {
            throw new InvalidTransactionInput('The transaction split must sum exactly to its amount.');
        }
    }
}
