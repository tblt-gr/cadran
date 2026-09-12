<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Transactions\Domain\InvalidTransaction;
use App\Module\Transactions\Domain\InvalidTransfer;
use App\Module\Transactions\Domain\TransactionRepository;
use App\Module\Transactions\Domain\TransferRepository;
use Symfony\Component\Clock\ClockInterface;

final readonly class VoidTransfer
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private TransactionRepository $transactions,
        private TransferRepository $transfers,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
        private PresentTransfer $presentTransfer,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(string $id, int $version): TransferView
    {
        $context = $this->caller->resolveContext();

        return $this->transactionBoundary->transactional(function () use ($context, $id, $version): TransferView {
            $current = $this->transfers->findForUpdate($context->workspace, $id);
            if (null === $current) {
                throw new TransferNotFound();
            }
            if ($version !== $current->version) {
                throw new StaleTransferVersion('The transfer changed concurrently.');
            }

            $now = $this->clock->now();
            try {
                $voided = $current->void($now);
            } catch (InvalidTransfer $exception) {
                throw new TransferConflict('The transfer cannot be voided.', previous: $exception);
            }

            // Ascending order, matching UpdateTransfer: a concurrent edit or
            // void of the same transfer then waits instead of deadlocking.
            $legIds = array_filter([$current->sourceTransactionId, $current->targetTransactionId, $current->feeTransactionId]);
            sort($legIds);
            $voidedLegs = [];
            $sourceLegBefore = null;
            $sourceLegAfter = null;
            foreach ($legIds as $legId) {
                $leg = $this->transactions->findForUpdate($context->workspace, $legId);
                if (null === $leg) {
                    throw new \UnexpectedValueException('A transfer leg is missing its transaction.');
                }
                try {
                    $voidedLeg = $leg->void($now, $context->actorId);
                } catch (InvalidTransaction $exception) {
                    throw new TransferConflict('The transfer cannot be voided.', previous: $exception);
                }
                if (!$this->transactions->update($voidedLeg, $leg->version)) {
                    throw new StaleTransferVersion('The transfer changed concurrently.');
                }
                $voidedLegs[] = [$leg, $voidedLeg];
                if ($legId === $current->sourceTransactionId) {
                    $sourceLegBefore = $leg;
                    $sourceLegAfter = $voidedLeg;
                }
            }
            if (null === $sourceLegBefore || null === $sourceLegAfter) {
                throw new \UnexpectedValueException('A transfer is missing its source leg.');
            }

            if (!$this->transfers->update($voided, $current->version)) {
                throw new StaleTransferVersion('The transfer changed concurrently.');
            }

            foreach ($voidedLegs as [$before, $after]) {
                ($this->recordAuditEvent)(new AuditEventRecord(
                    $context->workspace, $context->actorId, TransactionAuditEvents::VOIDED,
                    TransactionAuditEvents::ENTITY, $after->id,
                    AuditDiff::change(TransactionAuditFingerprint::of($before), TransactionAuditFingerprint::of($after)),
                ));
            }
            $crossAsset = null !== $current->exchangeRate;
            ($this->recordAuditEvent)(new AuditEventRecord(
                $context->workspace, $context->actorId, TransferAuditEvents::VOIDED,
                TransferAuditEvents::ENTITY, $voided->id,
                AuditDiff::change(
                    TransferAuditFingerprint::of($current, $crossAsset, $sourceLegBefore->state),
                    TransferAuditFingerprint::of($voided, $crossAsset, $sourceLegAfter->state),
                ),
            ));

            return $this->presentTransfer->one($voided);
        });
    }
}
