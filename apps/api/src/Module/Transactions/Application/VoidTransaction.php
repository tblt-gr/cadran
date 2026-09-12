<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Transactions\Domain\InvalidTransaction;
use App\Module\Transactions\Domain\RefundRepository;
use App\Module\Transactions\Domain\TransactionRepository;
use App\Module\Transactions\Domain\TransferRepository;
use Symfony\Component\Clock\ClockInterface;

final readonly class VoidTransaction
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private TransactionRepository $transactions,
        private RefundRepository $refunds,
        private TransferRepository $transfers,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
        private PresentTransaction $presentTransaction,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(string $id, int $version): TransactionView
    {
        $context = $this->caller->resolveContext();

        return $this->transactionBoundary->transactional(function () use ($context, $id, $version): TransactionView {
            $current = $this->transactions->findForUpdate($context->workspace, $id);
            if (null === $current) {
                throw new TransactionNotFound();
            }
            $transfer = $this->transfers->findByLegTransactionId($context->workspace, $id);
            if (null !== $transfer) {
                throw new TransactionBelongsToTransfer($transfer->id);
            }
            if ([] !== $this->refunds->findByOriginalTransactionId($context->workspace, $id)) {
                foreach ($this->refunds->findByOriginalTransactionId($context->workspace, $id) as $linkedRefund) {
                    $refund = $this->transactions->find($context->workspace, $linkedRefund->refundTransactionId);
                    if (null !== $refund && !$refund->state->isTerminal()) {
                        throw new TransactionHasRefunds('A transaction with live refunds cannot be voided.');
                    }
                }
            }
            if ($version !== $current->version) {
                throw new StaleTransactionVersion('The transaction changed concurrently.');
            }
            if ($current->state->isTerminal()) {
                throw new TransactionConflict('A terminal transaction cannot be voided.');
            }
            try {
                $voided = $current->void($this->clock->now(), $context->actorId);
            } catch (InvalidTransaction $exception) {
                throw new TransactionConflict('The transaction cannot be voided.', previous: $exception);
            }
            if (!$this->transactions->update($voided, $current->version)) {
                throw new StaleTransactionVersion('The transaction changed concurrently.');
            }
            ($this->recordAuditEvent)(new AuditEventRecord(
                $context->workspace, $context->actorId, TransactionAuditEvents::VOIDED,
                TransactionAuditEvents::ENTITY, $voided->id,
                AuditDiff::change(TransactionAuditFingerprint::of($current), TransactionAuditFingerprint::of($voided)),
            ));

            return $this->presentTransaction->one($voided);
        });
    }
}
