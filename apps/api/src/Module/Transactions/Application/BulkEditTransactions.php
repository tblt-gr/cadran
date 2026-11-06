<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Categories\Domain\Category;
use App\Module\Categories\Domain\CategoryRepository;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Application\WorkspaceContext;
use App\Module\Foundation\Domain\UuidGenerator;
use App\Module\Transactions\Application\Categorization\CategorizationWriteLock;
use App\Module\Transactions\Domain\BulkEditPolicy;
use App\Module\Transactions\Domain\BulkEditSkipReason;
use App\Module\Transactions\Domain\CategorizationOrigin;
use App\Module\Transactions\Domain\InvalidTransaction;
use App\Module\Transactions\Domain\PaymentMethod;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionRepository;
use App\Module\Transactions\Domain\TransactionSplit;
use Symfony\Component\Clock\ClockInterface;

/**
 * Edits the label, payment method, note, counterparty and category of many
 * transactions in one PostgreSQL transaction. Eligible rows are written, the
 * others are reported with their reason and left untouched; nothing is written
 * when none is eligible. A category refusal alone leaves that field of the row
 * unchanged while its other fields are still written.
 */
final readonly class BulkEditTransactions
{
    /** Upper bound of one operation, for an explicit selection and for a filter scope alike. */
    public const int MAX_TRANSACTIONS = 500;

    public function __construct(
        private CallerWorkspaceContext $caller,
        private TransactionRepository $transactions,
        private CategoryRepository $categories,
        private ResolveBulkScope $scope,
        private ReadBulkEditFacts $readFacts,
        private TransactionBoundary $transactionBoundary,
        private CategorizationWriteLock $categorizationWriteLock,
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
    public function __invoke(BulkEditInput $input): BulkEditResult
    {
        $context = $this->caller->resolveContext();
        $changes = $input->changes;

        return $this->transactionBoundary->transactional(function () use ($context, $input, $changes): BulkEditResult {
            $this->categorizationWriteLock->acquire($context->workspace);
            $category = null;
            if (null !== $changes->categoryId) {
                $category = $this->categories->findForUpdate($context->workspace, $changes->categoryId);
                if (null === $category || null !== $category->archivedAt) {
                    throw new TransactionNotFound();
                }
            }

            $ids = $this->scope->ids($context->workspace, $input);
            $rows = [];
            foreach ($this->transactions->findManyForUpdate($context->workspace, $ids) as $row) {
                $rows[$row->id] = $row;
            }
            // The scope was resolved before the rows were locked: a concurrent edit may have moved
            // one out of the filter since, so membership is verified again now that they are held.
            $stillMatching = null === $input->ids ? array_flip($this->scope->matchingIds($context->workspace, $input)) : null;
            $facts = ($this->readFacts)($context->workspace, $rows);

            $now = $this->clock->now();
            $skipped = [];
            $fields = [];
            $changed = 0;
            $days = [];
            foreach ($ids as $id) {
                $current = $rows[$id] ?? null;
                if (null === $current) {
                    $skipped[$id] = BulkEditSkipReason::NOT_FOUND;
                    continue;
                }
                if (null !== $stillMatching && !isset($stillMatching[$id])) {
                    $skipped[$id] = BulkEditSkipReason::NO_LONGER_MATCHING;
                    continue;
                }
                $label = $changes->label?->valueFor($id);
                $reason = BulkEditPolicy::rowReason($current, $facts[$id], $label);
                if (null !== $reason) {
                    $skipped[$id] = $reason;
                    continue;
                }
                $categoryReason = null === $category ? null : BulkEditPolicy::categoryReason($current, $category->id, $category->type, $facts[$id]);
                if (null !== $categoryReason) {
                    $skipped[$id] = $categoryReason;
                    if (!$changes->editsOtherThanCategory()) {
                        continue;
                    }
                }
                $applyCategory = null !== $category && null === $categoryReason;
                $applied = $this->appliedFields($changes, $applyCategory);
                $updated = $this->edited($current, $changes, $label, $applyCategory ? $category : null, $context->actorId, $now);
                // No recurrence re-match here, unlike a single edit: no amount or date changes.
                if (!$this->transactions->update($updated, $current->version)) {
                    throw new StaleTransactionVersion('The transaction changed concurrently.');
                }
                ++$changed;
                $fields += array_fill_keys($applied, true);
                $days[$current->bookedOn->format('Y-m')] = $current->bookedOn->format('Y-m-d');
            }

            $fieldsChanged = array_keys($fields);
            if (0 < $changed) {
                if (isset($fields['categoryId']) && null !== $category) {
                    $used = $category->markUsed($now);
                    if ($used !== $category && !$this->categories->update($used, $category->version)) {
                        throw new TransactionConflict('The transaction category changed concurrently.');
                    }
                }
                $this->audit($context, $input, count($ids), $changed, $fieldsChanged, $skipped);
            }

            return new BulkEditResult(count($ids), $changed, $skipped, $fieldsChanged, array_values($days));
        });
    }

    /** @return list<string> the changes this row takes, in the order of the request body */
    private function appliedFields(BulkEditChanges $changes, bool $category): array
    {
        return array_keys(array_filter([
            'label' => null !== $changes->label,
            'paymentMethod' => null !== $changes->paymentMethod,
            'note' => null !== $changes->note,
            'counterparty' => null !== $changes->counterparty,
            'categoryId' => $category,
        ]));
    }

    private function edited(
        Transaction $current,
        BulkEditChanges $changes,
        ?string $label,
        ?Category $category,
        ?string $actorId,
        \DateTimeImmutable $now,
    ): Transaction {
        $external = BulkEditPolicy::isExternal($current);
        try {
            return $current->edit(
                amount: $current->amount, nature: $current->nature, state: $current->state,
                bookedOn: $current->bookedOn, valueOn: $current->valueOn, authorizedOn: $current->authorizedOn,
                rawLabel: null === $label || $external ? $current->rawLabel : $label,
                counterparty: null === $changes->counterparty ? $current->counterparty : self::blankToNull($changes->counterparty->value),
                note: null === $changes->note ? $current->note : self::blankToNull($changes->note->value),
                paymentMethod: null === $changes->paymentMethod
                    ? $current->paymentMethod
                    : (null === $changes->paymentMethod->value ? null : PaymentMethod::from($changes->paymentMethod->value)),
                mcc: $current->mcc, maskedCard: $current->maskedCard, bankReference: $current->bankReference,
                splits: null === $category ? $current->splits : [$this->split($current, $category, $now)],
                updatedAt: $now, lastEditorId: $actorId,
            )->withUserLabel(match (true) {
                null === $label => $current->userLabel,
                $external => self::blankToNull($label),
                default => null,
            });
        } catch (InvalidTransaction|\ValueError $exception) {
            throw new InvalidTransactionInput('The requested bulk edit is invalid.', previous: $exception);
        }
    }

    private function split(Transaction $current, Category $category, \DateTimeImmutable $now): TransactionSplit
    {
        $existing = $current->splits[0] ?? null;

        return new TransactionSplit(
            id: $existing->id ?? $this->uuidGenerator->generate(),
            workspace: $current->workspace,
            transactionId: $current->id,
            categoryId: $category->id,
            amount: $current->amount,
            analyticAxes: $category->defaultAnalyticAxes,
            note: null,
            createdAt: $existing->createdAt ?? $now,
            origin: CategorizationOrigin::MANUAL,
        );
    }

    private static function blankToNull(?string $text): ?string
    {
        $trimmed = null === $text ? '' : trim($text);

        return '' === $trimmed ? null : $trimmed;
    }

    /**
     * Field names and counts only: a label, note, counterparty or amount never reaches the trail.
     *
     * @param list<string>                      $fields
     * @param array<string, BulkEditSkipReason> $skipped
     */
    private function audit(WorkspaceContext $context, BulkEditInput $input, int $requested, int $changed, array $fields, array $skipped): void
    {
        $counts = [];
        foreach ($skipped as $reason) {
            $counts[$reason->value] = ($counts[$reason->value] ?? 0) + 1;
        }
        ksort($counts);
        ($this->recordAuditEvent)(new AuditEventRecord(
            $context->workspace, $context->actorId, TransactionAuditEvents::BULK_EDITED,
            TransactionAuditEvents::BATCH_ENTITY, $this->uuidGenerator->generate(),
            AuditDiff::creation([
                'scope' => null === $input->ids ? 'FILTER' : 'IDS',
                'requested' => $requested,
                'changed' => $changed,
                'fields' => implode(',', $fields),
                'skips' => implode(',', array_map(static fn (string $reason, int $count): string => $reason.':'.$count, array_keys($counts), $counts)),
            ]),
        ));
    }
}
