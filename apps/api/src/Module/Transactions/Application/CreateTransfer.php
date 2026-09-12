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
use App\Module\Transactions\Domain\InvalidTransfer;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionRepository;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\Transfer;
use App\Module\Transactions\Domain\TransferRepository;
use Symfony\Component\Clock\ClockInterface;

final readonly class CreateTransfer
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
    ) {
    }

    public function __invoke(CreateTransferInput $input): TransferView
    {
        $context = $this->caller->resolveContext();
        $draft = $this->parser->parse($input->state, $input->bookedOn, $input->valueOn, $input->label);

        return $this->transactionBoundary->transactional(function () use ($context, $input, $draft): TransferView {
            $state = $draft->state;
            $bookedOn = $draft->bookedOn;
            $valueOn = $draft->valueOn;
            $label = $draft->label;
            $now = $this->clock->now();
            $today = BusinessDay::fromIsoDate($now->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y-m-d'))->date;

            [$sourceAccount, $targetAccount] = $this->references->lockForCreation(
                $context->workspace, $input->sourceAccountId, $input->targetAccountId, $bookedOn, $today, $now,
            );
            $plan = $this->legFactory->plan($input->sourceAmount, $input->targetAmount, $sourceAccount, $targetAccount);
            $fee = $this->legFactory->fee($input->fee, $sourceAccount);

            $sourceId = $this->uuidGenerator->generate();
            $targetId = $this->uuidGenerator->generate();
            $feeId = null === $fee ? null : $this->uuidGenerator->generate();

            try {
                $sourceTransaction = new Transaction(
                    id: $sourceId, workspace: $context->workspace, accountId: $sourceAccount->id,
                    amount: $plan->sourceAmount, originalAmount: $plan->sourceOriginal, exchangeRate: $plan->exchangeRate,
                    state: $state, nature: TransactionNature::TRANSFER, source: TransactionSource::MANUAL, sourceRef: null,
                    bookedOn: $bookedOn, valueOn: $valueOn, authorizedOn: null, rawLabel: $label, counterparty: null,
                    note: $input->note, paymentMethod: null, mcc: null, maskedCard: null, bankReference: null,
                    splits: [], version: 1, createdAt: $now, updatedAt: $now, voidedAt: null, lastEditorId: $context->actorId,
                );
                $targetTransaction = new Transaction(
                    id: $targetId, workspace: $context->workspace, accountId: $targetAccount->id,
                    amount: $plan->targetAmount, originalAmount: $plan->targetOriginal, exchangeRate: $plan->exchangeRate,
                    state: $state, nature: TransactionNature::TRANSFER, source: TransactionSource::MANUAL, sourceRef: null,
                    bookedOn: $bookedOn, valueOn: $valueOn, authorizedOn: null, rawLabel: $label, counterparty: null,
                    note: $input->note, paymentMethod: null, mcc: null, maskedCard: null, bankReference: null,
                    splits: [], version: 1, createdAt: $now, updatedAt: $now, voidedAt: null, lastEditorId: $context->actorId,
                );
                $feeTransaction = null === $fee || null === $feeId ? null : new Transaction(
                    id: $feeId, workspace: $context->workspace, accountId: $sourceAccount->id,
                    amount: $fee, originalAmount: null, exchangeRate: null,
                    state: $state, nature: TransactionNature::FEE, source: TransactionSource::MANUAL, sourceRef: null,
                    bookedOn: $bookedOn, valueOn: $valueOn, authorizedOn: null, rawLabel: $label, counterparty: null,
                    note: $input->note, paymentMethod: null, mcc: null, maskedCard: null, bankReference: null,
                    splits: [], version: 1, createdAt: $now, updatedAt: $now, voidedAt: null, lastEditorId: $context->actorId,
                );
                $transfer = new Transfer(
                    id: $this->uuidGenerator->generate(), workspace: $context->workspace,
                    sourceTransactionId: $sourceId, targetTransactionId: $targetId, feeTransactionId: $feeId,
                    exchangeRate: $plan->exchangeRate, version: 1, createdAt: $now, updatedAt: $now, voidedAt: null,
                );
            } catch (InvalidTransaction|InvalidTransfer $exception) {
                throw new InvalidTransferInput('The transfer input is invalid.', previous: $exception);
            }

            $this->transactions->add($sourceTransaction);
            $this->transactions->add($targetTransaction);
            if (null !== $feeTransaction) {
                $this->transactions->add($feeTransaction);
            }
            $this->transfers->add($transfer);

            foreach (array_filter([$sourceTransaction, $targetTransaction, $feeTransaction]) as $leg) {
                ($this->recordAuditEvent)(new AuditEventRecord(
                    $context->workspace, $context->actorId, TransactionAuditEvents::CREATED,
                    TransactionAuditEvents::ENTITY, $leg->id,
                    AuditDiff::creation(TransactionAuditFingerprint::of($leg)),
                ));
            }
            ($this->recordAuditEvent)(new AuditEventRecord(
                $context->workspace, $context->actorId, TransferAuditEvents::CREATED,
                TransferAuditEvents::ENTITY, $transfer->id,
                AuditDiff::creation(TransferAuditFingerprint::of($transfer, $plan->crossAsset, $state)),
            ));

            // Re-read rather than present the in-memory value: NUMERIC(50,24)
            // round-trips the rate through the same trimming as each leg's own
            // exchangeRate, so a client comparing this response to a later GET
            // never sees a phantom change in trailing zeros.
            $stored = $this->transfers->find($context->workspace, $transfer->id)
                ?? throw new \UnexpectedValueException('A transfer disappeared right after being created.');

            return $this->presentTransfer->one($stored);
        });
    }
}
