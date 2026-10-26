<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Accounts\Application\AssertPeriodOpen;
use App\Module\Accounts\Application\PeriodClosed;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Categories\Domain\Category;
use App\Module\Categories\Domain\CategoryRepository;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Domain\UuidGenerator;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Application\Categorization\CategorizationWriteLock;
use App\Module\Transactions\Domain\BulkCategorizationFacts;
use App\Module\Transactions\Domain\BulkCategorizationPolicy;
use App\Module\Transactions\Domain\BulkCategorizationSkipReason;
use App\Module\Transactions\Domain\CategorizationOrigin;
use App\Module\Transactions\Domain\InvalidTransaction;
use App\Module\Transactions\Domain\RefundRepository;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionRepository;
use App\Module\Transactions\Domain\TransactionSplit;
use App\Module\Transactions\Domain\TransferRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * Moves many single-category transactions to one category in one PostgreSQL
 * transaction. Eligible rows are written, the others are reported with their
 * reason and left untouched; nothing is written when none is eligible.
 */
final readonly class BulkRecategorizeTransactions
{
    /** Upper bound of one operation, for an explicit selection and for a filter scope alike. */
    public const int MAX_TRANSACTIONS = 500;

    public function __construct(
        private CallerWorkspaceContext $caller,
        private TransactionRepository $transactions,
        private RefundRepository $refunds,
        private TransferRepository $transfers,
        private CategoryRepository $categories,
        private ResolveTransactionFilters $resolveFilters,
        private TransactionBoundary $transactionBoundary,
        private CategorizationWriteLock $categorizationWriteLock,
        private AssertPeriodOpen $assertPeriodOpen,
        private RecordAuditEvent $recordAuditEvent,
        private UuidGenerator $uuidGenerator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws BulkScopeTooLarge
     * @throws BulkScopeChanged
     * @throws InvalidTransactionInput
     * @throws TransactionNotFound
     */
    public function __invoke(BulkCategorizeInput $input): BulkCategorizeResult
    {
        $context = $this->caller->resolveContext();

        return $this->transactionBoundary->transactional(function () use ($context, $input): BulkCategorizeResult {
            $this->categorizationWriteLock->acquire($context->workspace);
            $category = $this->categories->findForUpdate($context->workspace, $input->categoryId);
            if (null === $category) {
                throw new TransactionNotFound();
            }
            if (null !== $category->archivedAt) {
                throw new InvalidTransactionInput('A transaction category must be active.');
            }

            $ids = $this->resolveIds($context->workspace, $input);
            $rows = [];
            foreach ($this->transactions->findManyForUpdate($context->workspace, $ids) as $row) {
                $rows[$row->id] = $row;
            }
            // The scope was resolved before the rows were locked: a concurrent edit may have moved
            // one out of the filter since, so membership is verified again now that they are held.
            $stillMatching = null === $input->ids ? array_flip($this->matchingIds($context->workspace, $input)) : null;
            $facts = $this->facts($context->workspace, array_keys($rows), $rows);

            $now = $this->clock->now();
            $skipped = [];
            $changedIds = [];
            $days = [];
            foreach ($ids as $id) {
                $current = $rows[$id] ?? null;
                if (null === $current) {
                    $skipped[$id] = BulkCategorizationSkipReason::NOT_FOUND;
                    continue;
                }
                if (null !== $stillMatching && !isset($stillMatching[$id])) {
                    $skipped[$id] = BulkCategorizationSkipReason::NO_LONGER_MATCHING;
                    continue;
                }
                $reason = BulkCategorizationPolicy::skipReason($current, $category->id, $category->type, $facts[$id]);
                if (null !== $reason) {
                    $skipped[$id] = $reason;
                    continue;
                }
                $updated = $this->recategorized($current, $category, $context->actorId, $now);
                if (!$this->transactions->update($updated, $current->version)) {
                    throw new StaleTransactionVersion('The transaction changed concurrently.');
                }
                ($this->recordAuditEvent)(new AuditEventRecord(
                    $context->workspace, $context->actorId, TransactionAuditEvents::BULK_CATEGORIZED,
                    TransactionAuditEvents::ENTITY, $updated->id,
                    AuditDiff::change(TransactionAuditFingerprint::of($current), TransactionAuditFingerprint::of($updated)),
                ));
                $changedIds[] = $id;
                $days[$current->bookedOn->format('Y-m')] = $current->bookedOn->format('Y-m-d');
            }

            if ([] !== $changedIds) {
                $used = $category->markUsed($now);
                if ($used !== $category && !$this->categories->update($used, $category->version)) {
                    throw new TransactionConflict('The transaction category changed concurrently.');
                }
                ($this->recordAuditEvent)(new AuditEventRecord(
                    $context->workspace, $context->actorId, TransactionAuditEvents::BULK_CATEGORIZATION_RUN,
                    TransactionAuditEvents::BATCH_ENTITY, $this->uuidGenerator->generate(),
                    AuditDiff::creation([
                        'categoryId' => $category->id,
                        'scope' => null === $input->ids ? 'FILTER' : 'IDS',
                        'requested' => count($ids),
                        'changed' => count($changedIds),
                        'skipped' => count($skipped),
                    ]),
                ));
            }

            return new BulkCategorizeResult(count($ids), count($changedIds), $skipped, array_values($days));
        });
    }

    /**
     * Sorted and deduplicated: it is the lock order, and a repeated identifier
     * must not be counted twice.
     *
     * @return list<string>
     */
    private function resolveIds(WorkspaceScope $workspace, BulkCategorizeInput $input): array
    {
        if (null !== $input->ids) {
            $ids = array_values(array_unique($input->ids));
            if (count($ids) > self::MAX_TRANSACTIONS) {
                throw new BulkScopeTooLarge();
            }
            sort($ids);

            return $ids;
        }

        $ids = $this->matchingIds($workspace, $input);
        if (count($ids) > self::MAX_TRANSACTIONS) {
            throw new BulkScopeTooLarge();
        }
        if (count($ids) !== $input->expectedCount) {
            throw new BulkScopeChanged(count($ids));
        }

        return $ids;
    }

    /**
     * At most one more than the cap, so an oversized scope is detected without reading it all.
     *
     * @return list<string> sorted identifiers
     */
    private function matchingIds(WorkspaceScope $workspace, BulkCategorizeInput $input): array
    {
        $query = $input->filters ?? throw new \LogicException('A bulk scope names identifiers or filters.');
        $filters = ($this->resolveFilters)($workspace, $query);
        $found = null === $filters ? [] : $this->transactions->search($workspace, $filters, self::MAX_TRANSACTIONS + 1, null);
        $ids = array_map(static fn (Transaction $transaction): string => $transaction->id, $found);
        sort($ids);

        return $ids;
    }

    /**
     * @param list<string>               $ids
     * @param array<string, Transaction> $rows
     *
     * @return array<string, BulkCategorizationFacts>
     */
    private function facts(WorkspaceScope $workspace, array $ids, array $rows): array
    {
        $legs = $this->transfers->markersForLegs($workspace, $ids);
        $refundOf = $this->refunds->originalsByRefundTransactionId($workspace, $ids);
        $refunded = $this->refunds->liveRefundedAmounts($workspace, $ids);
        $closedMonths = [];
        foreach ($rows as $row) {
            $month = $row->bookedOn->format('Y-m');
            if (isset($closedMonths[$month])) {
                continue;
            }
            try {
                ($this->assertPeriodOpen)($workspace, $row->bookedOn);
                $closedMonths[$month] = false;
            } catch (PeriodClosed) {
                $closedMonths[$month] = true;
            }
        }

        $facts = [];
        foreach ($rows as $id => $row) {
            $facts[$id] = new BulkCategorizationFacts(
                transferLeg: isset($legs[$id]),
                refund: isset($refundOf[$id]),
                liveRefund: isset($refunded[$id]),
                periodClosed: $closedMonths[$row->bookedOn->format('Y-m')],
            );
        }

        return $facts;
    }

    private function recategorized(Transaction $current, Category $category, ?string $actorId, \DateTimeImmutable $now): Transaction
    {
        $existing = $current->splits[0] ?? null;
        try {
            return $current->edit(
                amount: $current->amount, nature: $current->nature, state: $current->state,
                bookedOn: $current->bookedOn, valueOn: $current->valueOn, authorizedOn: $current->authorizedOn,
                rawLabel: $current->rawLabel, counterparty: $current->counterparty, note: $current->note,
                paymentMethod: $current->paymentMethod, mcc: $current->mcc, maskedCard: $current->maskedCard,
                bankReference: $current->bankReference,
                splits: [new TransactionSplit(
                    id: $existing->id ?? $this->uuidGenerator->generate(),
                    workspace: $current->workspace,
                    transactionId: $current->id,
                    categoryId: $category->id,
                    amount: $current->amount,
                    analyticAxes: $category->defaultAnalyticAxes,
                    note: null,
                    createdAt: $existing->createdAt ?? $now,
                    origin: CategorizationOrigin::MANUAL,
                )],
                updatedAt: $now, lastEditorId: $actorId,
            );
        } catch (InvalidTransaction $exception) {
            throw new InvalidTransactionInput('The requested recategorisation is invalid.', previous: $exception);
        }
    }
}
