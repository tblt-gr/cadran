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
use App\Module\Transactions\Application\Categorization\AutoCategorizeTransaction;
use App\Module\Transactions\Application\Categorization\CategorizationWriteLock;
use App\Module\Transactions\Domain\InvalidTransaction;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionRepository;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\TransactionSplit;
use App\Module\Transactions\Domain\TransactionState;
use Symfony\Component\Clock\ClockInterface;

final readonly class CreateTransaction
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private TransactionRepository $transactions,
        private TransactionInputParser $parser,
        private TransactionSplitInputParser $splitParser,
        private TransactionReferences $references,
        private UuidGenerator $uuidGenerator,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
        private PresentTransaction $presentTransaction,
        private ClockInterface $clock,
        private AutoCategorizeTransaction $autoCategorize,
        private CategorizationWriteLock $categorizationWriteLock,
    ) {
    }

    public function __invoke(CreateTransactionInput $input): TransactionView
    {
        $context = $this->caller->resolveContext();
        $source = TransactionSource::tryFrom($input->source);
        if (null === $source) {
            throw new InvalidTransactionInput('The transaction source is invalid.');
        }
        $draft = $this->parser->parse(
            $input->amount, $input->nature, $input->state, $input->bookedOn, $input->valueOn,
            $input->authorizedOn, $input->rawLabel, $input->counterparty, $input->note,
            $input->paymentMethod, $input->mcc, $input->maskedCard, $input->bankReference,
        );
        if (!in_array($draft->state, [TransactionState::PENDING, TransactionState::BOOKED], true)) {
            throw new InvalidTransactionInput('A new transaction must be pending or booked.');
        }

        return $this->transactionBoundary->transactional(function () use ($context, $draft, $input, $source): TransactionView {
            $this->categorizationWriteLock->acquire($context->workspace);
            $now = $this->clock->now();
            $today = BusinessDay::fromIsoDate($now->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y-m-d'))->date;
            $this->references->accountForNew($context->workspace, $input->accountId, $draft, $today, $now);
            $id = $this->uuidGenerator->generate();

            try {
                $splits = null === $input->splits
                    ? self::wrap($this->references->split($context->workspace, $id, $input->categoryId, $draft, $now))
                    : $this->references->splits($context->workspace, $id, $this->splitParser->parse($input->splits), $draft->amount, $now);
                $transaction = new Transaction(
                    id: $id, workspace: $context->workspace, accountId: $input->accountId, amount: $draft->amount,
                    originalAmount: null, exchangeRate: null, state: $draft->state, nature: $draft->nature,
                    source: $source, sourceRef: null, bookedOn: $draft->bookedOn,
                    valueOn: $draft->valueOn, authorizedOn: $draft->authorizedOn, rawLabel: $draft->rawLabel,
                    counterparty: $draft->counterparty, note: $draft->note, paymentMethod: $draft->paymentMethod,
                    mcc: $draft->mcc, maskedCard: $draft->maskedCard, bankReference: $draft->bankReference,
                    splits: $splits, version: 1, createdAt: $now, updatedAt: $now,
                    voidedAt: null, lastEditorId: $context->actorId,
                );
                if (null === $input->splits && null === $input->categoryId) {
                    $transaction = ($this->autoCategorize)($transaction, $context->actorId, $now);
                }
            } catch (InvalidTransaction $exception) {
                throw new InvalidTransactionInput('The transaction input is invalid.', previous: $exception);
            }
            $this->transactions->add($transaction);
            ($this->recordAuditEvent)(new AuditEventRecord(
                $context->workspace, $context->actorId, TransactionAuditEvents::CREATED,
                TransactionAuditEvents::ENTITY, $transaction->id,
                AuditDiff::creation(TransactionAuditFingerprint::of($transaction)),
            ));

            return $this->presentTransaction->one($transaction);
        });
    }

    /** @return list<TransactionSplit> */
    private static function wrap(?TransactionSplit $split): array
    {
        return null === $split ? [] : [$split];
    }
}
