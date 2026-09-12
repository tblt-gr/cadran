<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Catalog\Domain\BusinessDay;
use App\Module\Foundation\Application\AmountInputParser;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Foundation\Domain\UuidGenerator;
use App\Module\Reference\Application\AssetCatalog;
use App\Module\Transactions\Domain\InvalidTransaction;
use App\Module\Transactions\Domain\RefundRepository;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionRefund;
use App\Module\Transactions\Domain\TransactionRepository;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\TransactionState;
use Symfony\Component\Clock\ClockInterface;

final readonly class CreateRefund
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private TransactionRepository $transactions,
        private RefundRepository $refunds,
        private AmountInputParser $amountParser,
        private TransactionSplitInputParser $splitParser,
        private TransactionReferences $references,
        private AssetCatalog $assets,
        private RefundAllocation $allocation,
        private UuidGenerator $uuidGenerator,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
        private PresentTransaction $presentTransaction,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(string $originalId, CreateRefundInput $input): TransactionView
    {
        $context = $this->caller->resolveContext();
        try {
            $magnitude = ($this->amountParser)($input->amount, '/amount');
            if ($magnitude->value->isNegative() || 0 === $magnitude->value->compareTo(DecimalValue::zero())) {
                throw new \DomainException('A refund request carries a positive magnitude.');
            }
            $bookedOn = BusinessDay::fromIsoDate($input->bookedOn)->date;
        } catch (\Throwable $exception) {
            throw new InvalidTransactionInput('The refund input is invalid.', previous: $exception);
        }

        return $this->transactionBoundary->transactional(function () use ($context, $originalId, $input, $magnitude, $bookedOn): TransactionView {
            // This is deliberately the first transaction lock. Every refund
            // writer serialises here before observing and consuming the cap.
            $original = $this->transactions->findForUpdate($context->workspace, $originalId);
            if (null === $original) {
                throw new TransactionNotFound();
            }
            $this->assertOriginal($original, $bookedOn);
            $refunded = $this->refunds->refundedAmount($context->workspace, $original->id);
            $remaining = ExactDecimal::subtract(ExactDecimal::absolute($original->amount->value), $refunded);
            if (0 === $remaining->compareTo(DecimalValue::zero())) {
                throw new RefundConflict('already_settled', $remaining);
            }
            if ($magnitude->value->compareTo($remaining) > 0) {
                throw new RefundConflict('exceeds_refundable', $remaining);
            }
            if ($magnitude->asset->toString() !== $original->amount->asset->toString()) {
                throw new InvalidRefundRule('asset_mismatch');
            }
            $amount = $magnitude;
            $draft = new TransactionDraft($amount, TransactionNature::REFUND, TransactionState::BOOKED, $bookedOn, null, null,
                $input->rawLabel, $input->counterparty, $input->note, null, null, null, null);
            $now = $this->clock->now();
            $today = BusinessDay::fromIsoDate($now->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y-m-d'))->date;
            $account = $this->references->accountForNew($context->workspace, $input->accountId, $draft, $today, $now);
            if ($account->assetCode->toString() !== $original->amount->asset->toString()) {
                throw new InvalidRefundRule('asset_mismatch');
            }
            $id = $this->uuidGenerator->generate();
            $asset = $this->assets->findByCode($amount->asset) ?? throw new \UnexpectedValueException('Transaction asset is missing.');
            $proposal = $this->allocation->propose($original, $magnitude, $asset->precision->display);
            if ([] !== $original->splits && [] === $input->splits) {
                throw new InvalidSplitsInput(InvalidSplitsInput::SUM_MISMATCH, ['%amount%' => $amount->value->toString()]);
            }
            try {
                $splitInputs = null === $input->splits
                    ? array_map(static fn (array $row): TransactionSplitInput => new TransactionSplitInput(
                        $row['categoryId'], $row['amount'], null, null,
                    ), $proposal)
                    : array_map(static fn (TransactionSplitInput $row): TransactionSplitInput => new TransactionSplitInput(
                        $row->categoryId, $row->amount, $row->analyticAxes, $row->note,
                    ), $this->splitParser->parse($input->splits));
                $splits = $this->references->splits(
                    $context->workspace, $id, $splitInputs, $amount, $now, allowArchivedCategories: true, nature: TransactionNature::REFUND,
                );
                $refund = new Transaction(
                    $id, $context->workspace, $input->accountId, $amount, null, null, TransactionState::BOOKED,
                    TransactionNature::REFUND, TransactionSource::MANUAL, null, $bookedOn, null, null,
                    $input->rawLabel, $input->counterparty, $input->note, null, null, null, null, $splits, 1, $now, $now, null, $context->actorId,
                );
            } catch (InvalidTransaction $exception) {
                throw new InvalidTransactionInput('The refund input is invalid.', previous: $exception);
            }
            $this->transactions->add($refund);
            $this->refunds->add(new TransactionRefund($this->uuidGenerator->generate(), $context->workspace, $refund->id, $original->id, $now));
            ($this->recordAuditEvent)(new AuditEventRecord(
                $context->workspace, $context->actorId, TransactionAuditEvents::CREATED, TransactionAuditEvents::ENTITY,
                $refund->id, AuditDiff::creation(TransactionAuditFingerprint::of($refund)),
            ));

            return $this->presentTransaction->one($refund);
        });
    }

    private function assertOriginal(Transaction $original, \DateTimeImmutable $refundDate): void
    {
        if (TransactionState::BOOKED !== $original->state) {
            throw new InvalidRefundRule('original_not_refundable');
        }
        if (!in_array($original->nature, [TransactionNature::EXPENSE, TransactionNature::FEE], true)) {
            throw new InvalidRefundRule('original_nature');
        }
        if ($refundDate < $original->bookedOn) {
            throw new InvalidRefundRule('date_before_original');
        }
    }
}
