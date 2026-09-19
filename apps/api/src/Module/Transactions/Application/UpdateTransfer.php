<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Accounts\Application\AssertPeriodOpen;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\UuidGenerator;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\InvalidTransaction;
use App\Module\Transactions\Domain\InvalidTransfer;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionRepository;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\TransferRepository;
use Symfony\Component\Clock\ClockInterface;

final readonly class UpdateTransfer
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private TransactionRepository $transactions,
        private TransferRepository $transfers,
        private TransferReferences $references,
        private TransferLegFactory $legFactory,
        private TransferInputParser $parser,
        private UuidGenerator $uuidGenerator,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
        private PresentTransfer $presentTransfer,
        private ClockInterface $clock,
        private WorkspaceCalendar $calendar,
        private AssertPeriodOpen $assertPeriodOpen,
    ) {
    }

    public function __invoke(string $id, UpdateTransferInput $input): TransferView
    {
        $context = $this->caller->resolveContext();
        $draft = $this->parser->parse($input->state, $input->bookedOn, $input->valueOn, $input->label);

        return $this->transactionBoundary->transactional(function () use ($context, $id, $input, $draft): TransferView {
            $current = $this->transfers->findForUpdate($context->workspace, $id);
            if (null === $current) {
                throw new TransferNotFound();
            }
            if ($input->version !== $current->version) {
                throw new StaleTransferVersion('The transfer changed concurrently.');
            }
            if (null !== $current->voidedAt) {
                throw new TransferConflict('A voided transfer cannot be edited.');
            }

            // Legs (and the fee, if any) are locked in ascending identifier
            // order so a concurrent edit or void of the same transfer waits
            // instead of deadlocking against this one.
            $legIds = array_filter([$current->sourceTransactionId, $current->targetTransactionId, $current->feeTransactionId]);
            sort($legIds);
            $legsById = [];
            foreach ($legIds as $legId) {
                $leg = $this->transactions->findForUpdate($context->workspace, $legId);
                if (null === $leg) {
                    throw new \UnexpectedValueException('A transfer leg is missing its transaction.');
                }
                $legsById[$legId] = $leg;
            }
            $sourceLeg = $legsById[$current->sourceTransactionId];
            $targetLeg = $legsById[$current->targetTransactionId];
            $existingFeeLeg = null === $current->feeTransactionId ? null : $legsById[$current->feeTransactionId];
            ($this->assertPeriodOpen)($context->workspace, $sourceLeg->bookedOn, $targetLeg->bookedOn, $draft->bookedOn);

            if ($input->sourceAccountId !== $sourceLeg->accountId || $input->targetAccountId !== $targetLeg->accountId) {
                throw new InvalidTransferInput('The transfer accounts are immutable.');
            }

            $now = $this->clock->now();
            $today = $this->calendar->today();
            [$sourceAccount] = $this->references->readForEdit(
                $context->workspace, $sourceLeg->accountId, $targetLeg->accountId, $draft->bookedOn, $today,
            );
            $newFee = $this->legFactory->fee($input->fee, $sourceAccount);

            try {
                $editedSource = $sourceLeg->edit(
                    amount: $sourceLeg->amount, nature: $sourceLeg->nature, state: $draft->state,
                    bookedOn: $draft->bookedOn, valueOn: $draft->valueOn, authorizedOn: null,
                    rawLabel: $draft->label, counterparty: null, note: $input->note, paymentMethod: null,
                    mcc: null, maskedCard: null, bankReference: null, splits: [],
                    updatedAt: $now, lastEditorId: $context->actorId,
                );
                $editedTarget = $targetLeg->edit(
                    amount: $targetLeg->amount, nature: $targetLeg->nature, state: $draft->state,
                    bookedOn: $draft->bookedOn, valueOn: $draft->valueOn, authorizedOn: null,
                    rawLabel: $draft->label, counterparty: null, note: $input->note, paymentMethod: null,
                    mcc: null, maskedCard: null, bankReference: null, splits: [],
                    updatedAt: $now, lastEditorId: $context->actorId,
                );
            } catch (InvalidTransaction $exception) {
                throw new InvalidTransferInput('The transfer input is invalid.', previous: $exception);
            }
            if (!$this->transactions->update($editedSource, $sourceLeg->version)
                || !$this->transactions->update($editedTarget, $targetLeg->version)) {
                throw new StaleTransferVersion('The transfer changed concurrently.');
            }

            $auditedLegs = [[$sourceLeg, $editedSource], [$targetLeg, $editedTarget]];
            $feeTransactionId = $current->feeTransactionId;

            if (null === $newFee && null !== $existingFeeLeg) {
                $auditedLegs[] = self::voidFee($this->transactions, $existingFeeLeg, $now, $context->actorId);
                $feeTransactionId = null;
            } elseif (null !== $newFee && null !== $existingFeeLeg) {
                $auditedLegs[] = self::editFee($this->transactions, $existingFeeLeg, $newFee, $draft, $input->note, $now, $context->actorId);
            } elseif (null !== $newFee) {
                $feeId = $this->uuidGenerator->generate();
                $createdFee = self::addFee($this->transactions, $feeId, $context->workspace, $sourceAccount->id, $newFee, $draft, $input->note, $now, $context->actorId);
                $auditedLegs[] = [null, $createdFee];
                $feeTransactionId = $feeId;
            }

            try {
                $edited = $current->edit($feeTransactionId, $current->exchangeRate, $now);
            } catch (InvalidTransfer $exception) {
                throw new TransferConflict('The transfer cannot be edited.', previous: $exception);
            }
            if (!$this->transfers->update($edited, $current->version)) {
                throw new StaleTransferVersion('The transfer changed concurrently.');
            }

            foreach ($auditedLegs as [$before, $after]) {
                ($this->recordAuditEvent)(new AuditEventRecord(
                    $context->workspace, $context->actorId,
                    null === $before ? TransactionAuditEvents::CREATED : TransactionAuditEvents::UPDATED,
                    TransactionAuditEvents::ENTITY, $after->id,
                    null === $before
                        ? AuditDiff::creation(TransactionAuditFingerprint::of($after))
                        : AuditDiff::change(TransactionAuditFingerprint::of($before), TransactionAuditFingerprint::of($after)),
                ));
            }
            $crossAsset = null !== $current->exchangeRate;
            ($this->recordAuditEvent)(new AuditEventRecord(
                $context->workspace, $context->actorId, TransferAuditEvents::UPDATED,
                TransferAuditEvents::ENTITY, $edited->id,
                AuditDiff::change(
                    TransferAuditFingerprint::of($current, $crossAsset, $sourceLeg->state),
                    TransferAuditFingerprint::of($edited, $crossAsset, $draft->state),
                ),
            ));

            return $this->presentTransfer->one($edited);
        });
    }

    /** @return array{Transaction, Transaction} */
    private static function voidFee(TransactionRepository $transactions, Transaction $existing, \DateTimeImmutable $now, string $actorId): array
    {
        try {
            $voided = $existing->void($now, $actorId);
        } catch (InvalidTransaction $exception) {
            throw new InvalidTransferInput('The transfer input is invalid.', previous: $exception);
        }
        if (!$transactions->update($voided, $existing->version)) {
            throw new StaleTransferVersion('The transfer changed concurrently.');
        }

        return [$existing, $voided];
    }

    /** @return array{Transaction, Transaction} */
    private static function editFee(
        TransactionRepository $transactions,
        Transaction $existing,
        AssetAmount $amount,
        TransferDraft $draft,
        ?string $note,
        \DateTimeImmutable $now,
        string $actorId,
    ): array {
        try {
            $edited = $existing->edit(
                amount: $amount, nature: $existing->nature, state: $draft->state,
                bookedOn: $draft->bookedOn, valueOn: $draft->valueOn, authorizedOn: null,
                rawLabel: $draft->label, counterparty: null, note: $note, paymentMethod: null,
                mcc: null, maskedCard: null, bankReference: null, splits: [],
                updatedAt: $now, lastEditorId: $actorId,
            );
        } catch (InvalidTransaction $exception) {
            throw new InvalidTransferInput('The transfer input is invalid.', previous: $exception);
        }
        if (!$transactions->update($edited, $existing->version)) {
            throw new StaleTransferVersion('The transfer changed concurrently.');
        }

        return [$existing, $edited];
    }

    private static function addFee(
        TransactionRepository $transactions,
        string $feeId,
        WorkspaceScope $workspace,
        string $sourceAccountId,
        AssetAmount $amount,
        TransferDraft $draft,
        ?string $note,
        \DateTimeImmutable $now,
        string $actorId,
    ): Transaction {
        try {
            $fee = new Transaction(
                id: $feeId, workspace: $workspace, accountId: $sourceAccountId,
                amount: $amount, originalAmount: null, exchangeRate: null,
                state: $draft->state, nature: TransactionNature::FEE, source: TransactionSource::MANUAL, sourceRef: null,
                bookedOn: $draft->bookedOn, valueOn: $draft->valueOn, authorizedOn: null, rawLabel: $draft->label,
                counterparty: null, note: $note, paymentMethod: null, mcc: null, maskedCard: null,
                bankReference: null, splits: [], version: 1, createdAt: $now, updatedAt: $now,
                voidedAt: null, lastEditorId: $actorId,
            );
        } catch (InvalidTransaction $exception) {
            throw new InvalidTransferInput('The transfer input is invalid.', previous: $exception);
        }
        $transactions->add($fee);

        return $fee;
    }
}
