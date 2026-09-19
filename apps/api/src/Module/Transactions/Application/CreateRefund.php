<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Accounts\Application\AssertPeriodOpen;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Catalog\Domain\BusinessDay;
use App\Module\Foundation\Application\AmountInputParser;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Domain\AssetAmount;
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
        private AccountRepository $accounts,
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
        private WorkspaceCalendar $calendar,
        private AssertPeriodOpen $assertPeriodOpen,
    ) {
    }

    public function __invoke(string $originalId, CreateRefundInput $input): CreatedRefund
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

        return $this->transactionBoundary->transactional(function () use ($context, $originalId, $input, $magnitude, $bookedOn): CreatedRefund {
            // This is deliberately the first transaction lock. Every refund
            // writer serialises here before observing and consuming the cap.
            $original = $this->transactions->findForUpdate($context->workspace, $originalId);
            if (null === $original) {
                throw new TransactionNotFound();
            }
            $this->assertOriginal($original, $bookedOn);
            ($this->assertPeriodOpen)($context->workspace, $original->bookedOn, $bookedOn);
            // Checked before the cap: comparing magnitudes across two assets
            // would otherwise let a foreign-currency amount masquerade as an
            // over- or under-cap request instead of the asset mismatch it is.
            if ($magnitude->asset->toString() !== $original->amount->asset->toString()) {
                throw new InvalidRefundRule('asset_mismatch');
            }
            $refunded = $this->refunds->refundedAmount($context->workspace, $original->id);
            $remaining = ExactDecimal::subtract(ExactDecimal::absolute($original->amount->value), $refunded);
            $remainingAmount = new AssetAmount($remaining, $original->amount->asset);
            if (0 === $remaining->compareTo(DecimalValue::zero())) {
                throw new RefundConflict('already_settled', $remainingAmount);
            }
            if ($magnitude->value->compareTo($remaining) > 0) {
                throw new RefundConflict('exceeds_refundable', $remainingAmount);
            }
            $amount = $magnitude;
            // Checked ahead of accountForNew(): that call's own asset invariant
            // compares against the refund's own amount, already proven equal to
            // the original's above, so it would otherwise mask this rule behind
            // a generic invalid-transaction problem instead of the refund one.
            $account = $this->accounts->find($context->workspace, $input->accountId);
            if (null === $account) {
                throw new TransactionNotFound();
            }
            if ($account->assetCode->toString() !== $original->amount->asset->toString()) {
                throw new InvalidRefundRule('asset_mismatch');
            }
            $draft = new TransactionDraft($amount, TransactionNature::REFUND, TransactionState::BOOKED, $bookedOn, null, null,
                $input->rawLabel, $input->counterparty, $input->note, null, null, null, null);
            $now = $this->clock->now();
            $today = $this->calendar->today();
            $this->references->accountForNew($context->workspace, $input->accountId, $draft, $today, $now);
            $id = $this->uuidGenerator->generate();
            $asset = $this->assets->findByCode($amount->asset) ?? throw new \UnexpectedValueException('Transaction asset is missing.');
            $proposal = $this->allocation->propose($original, $magnitude, $asset->precision->display);
            if ([] !== $original->splits && [] === $input->splits) {
                // The original is categorised, so an explicit empty array is a deliberate
                // refusal to allocate, not an "unset" default — the caller must state a
                // split set, even to keep the proposal above unchanged.
                throw new InvalidSplitsInput(InvalidSplitsInput::REQUIRED);
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

            return new CreatedRefund($this->presentTransaction->one($refund), $original->bookedOn->format('Y-m-d'));
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
