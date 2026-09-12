<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Catalog\Domain\BusinessDay;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Domain\UuidGenerator;
use App\Module\Transactions\Domain\InvalidTransaction;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionRepository;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\TransactionSplit;
use App\Module\Transactions\Domain\TransactionState;
use Symfony\Component\Clock\ClockInterface;

final readonly class DuplicateTransaction
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private TransactionRepository $transactions,
        private TransactionReferences $references,
        private UuidGenerator $uuidGenerator,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
        private PresentTransaction $presentTransaction,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(string $id): TransactionView
    {
        $context = $this->caller->resolveContext();

        return $this->transactionBoundary->transactional(function () use ($context, $id): TransactionView {
            $source = $this->transactions->findForUpdate($context->workspace, $id);
            if (null === $source) {
                throw new TransactionNotFound();
            }
            if (in_array($source->nature, [TransactionNature::TRANSFER, TransactionNature::REFUND], true)) {
                throw new InvalidTransactionInput('A transfer leg or refund cannot be duplicated.');
            }
            $now = $this->clock->now();
            $today = BusinessDay::fromIsoDate($now->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y-m-d'))->date;
            $draft = new TransactionDraft(
                $source->amount, $source->nature, TransactionState::BOOKED, $today, null, null,
                $source->rawLabel, $source->counterparty, $source->note, $source->paymentMethod,
                null, null, null,
            );
            $this->references->accountForNew($context->workspace, $source->accountId, $draft, $today, $now);
            $newId = $this->uuidGenerator->generate();

            try {
                // Archived categories stay allowed here: the source transaction was
                // valid when it was assigned, and this builds a brand-new split row
                // (its own fresh identity) rather than editing the source in place.
                $splits = [] === $source->splits
                    ? []
                    : $this->references->splits(
                        $context->workspace, $newId, array_map(
                            static fn (TransactionSplit $split): TransactionSplitInput => new TransactionSplitInput(
                                $split->categoryId, $split->amount, $split->analyticAxes, $split->note,
                            ),
                            $source->splits,
                        ),
                        $source->amount, $now, allowArchivedCategories: true,
                    );
                $duplicate = new Transaction(
                    id: $newId, workspace: $context->workspace, accountId: $source->accountId,
                    amount: $source->amount, originalAmount: null, exchangeRate: null,
                    state: TransactionState::BOOKED, nature: $source->nature, source: TransactionSource::MANUAL,
                    sourceRef: null, bookedOn: $today, valueOn: null, authorizedOn: null,
                    rawLabel: $source->rawLabel, counterparty: $source->counterparty, note: $source->note,
                    paymentMethod: $source->paymentMethod, mcc: null, maskedCard: null, bankReference: null,
                    splits: $splits, version: 1, createdAt: $now, updatedAt: $now,
                    voidedAt: null, lastEditorId: $context->actorId,
                );
            } catch (InvalidTransaction $exception) {
                throw new InvalidTransactionInput('The transaction cannot be duplicated.', previous: $exception);
            }
            $this->transactions->add($duplicate);
            ($this->recordAuditEvent)(new AuditEventRecord(
                $context->workspace, $context->actorId, TransactionAuditEvents::DUPLICATED,
                TransactionAuditEvents::ENTITY, $duplicate->id,
                AuditDiff::creation(TransactionAuditFingerprint::of($duplicate)),
            ));

            return $this->presentTransaction->one($duplicate);
        });
    }
}
