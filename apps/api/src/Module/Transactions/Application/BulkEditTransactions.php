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
use App\Module\Transactions\Application\Recurrence\MatchTransactionToOccurrence;
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
        private EvaluateBulkRows $evaluate,
        private BulkMoveAccounts $moveAccounts,
        private BulkPreviewTokenCodec $previewTokens,
        private MatchTransactionToOccurrence $matchRecurrence,
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
     * @throws BulkPreviewRequired
     * @throws BulkPreviewStale
     * @throws BulkTargetAccountNotFound
     * @throws InvalidTransactionInput
     * @throws TransactionNotFound
     */
    public function __invoke(BulkEditInput $input): BulkEditResult
    {
        $context = $this->caller->resolveContext();
        $changes = $input->changes;
        if ($changes->moves() && null === $input->previewToken) {
            throw new BulkPreviewRequired();
        }

        return $this->transactionBoundary->transactional(function () use ($context, $input, $changes): BulkEditResult {
            $this->categorizationWriteLock->acquire($context->workspace);
            $category = null;
            if (null !== $changes->categoryId) {
                $category = $this->categories->findForUpdate($context->workspace, $changes->categoryId);
                if (null === $category || null !== $category->archivedAt) {
                    throw new TransactionNotFound();
                }
            }
            $targetAccount = null === $changes->accountId ? null : $this->moveAccounts->target($context->workspace, $changes->accountId, true);
            $target = null === $targetAccount ? null : BulkMoveAccounts::describe($targetAccount);

            $ids = $this->scope->ids($context->workspace, $input);
            $rows = [];
            foreach ($this->transactions->findManyForUpdate($context->workspace, $ids) as $row) {
                $rows[$row->id] = $row;
            }
            $evaluation = ($this->evaluate)($context->workspace, $input, $ids, $rows, $target);
            // Recomputed from the rows as they are now that they are locked: any edit since the preview shows.
            if ($changes->moves() && !$this->previewTokens->accepts($input->previewToken, $context->workspace, $ids, $changes, $evaluation->eligibleVersions())) {
                throw new BulkPreviewStale();
            }

            $now = $this->clock->now();
            $skipped = $evaluation->skipped;
            $fields = [];
            $changed = 0;
            $days = [];
            foreach ($evaluation->eligible as $id => $current) {
                $label = $changes->label?->valueFor($id);
                $categoryReason = null === $category ? null : BulkEditPolicy::categoryReason($current, $category->id, $category->type, $evaluation->facts[$id]);
                if (null !== $categoryReason) {
                    $skipped[$id] = $categoryReason;
                    if (!$changes->editsOtherThanCategory()) {
                        continue;
                    }
                }
                $applyCategory = null !== $category && null === $categoryReason;
                $applied = $this->appliedFields($changes, $applyCategory);
                $updated = $this->edited($current, $changes, $label, $applyCategory ? $category : null, $context->actorId, $now);
                if (!$this->transactions->update($updated, $current->version)) {
                    throw new StaleTransactionVersion('The transaction changed concurrently.');
                }
                // The fingerprint holds no label, note, counterparty or amount, so the row trail is safe.
                ($this->recordAuditEvent)(new AuditEventRecord(
                    $context->workspace, $context->actorId, TransactionAuditEvents::BULK_ROW_EDITED,
                    TransactionAuditEvents::ENTITY, $updated->id,
                    AuditDiff::change(TransactionAuditFingerprint::of($current), TransactionAuditFingerprint::of($updated)),
                ));
                if ($changes->moves()) {
                    // A moved row may now settle another instalment, as after a single edit.
                    ($this->matchRecurrence)($updated);
                    $days[$updated->bookedOn->format('Y-m')] = $updated->bookedOn->format('Y-m-d');
                }
                ++$changed;
                $fields += array_fill_keys($applied, true);
                $days[$current->bookedOn->format('Y-m')] = $current->bookedOn->format('Y-m-d');
            }
            ksort($skipped);

            $fieldsChanged = array_keys($fields);
            $accountIds = array_map(static fn (BulkMoveImpact $impact): string => $impact->accountId, $evaluation->impacts);
            sort($accountIds);
            if (0 < $changed) {
                if (isset($fields['categoryId']) && null !== $category) {
                    $used = $category->markUsed($now);
                    if ($used !== $category && !$this->categories->update($used, $category->version)) {
                        throw new TransactionConflict('The transaction category changed concurrently.');
                    }
                }
                if (null !== $targetAccount) {
                    $this->moveAccounts->markUsed($targetAccount, $now);
                }
                $this->audit($context, $input, count($ids), $changed, $fieldsChanged, $skipped, $category?->id, $accountIds);
            }

            return new BulkEditResult(count($ids), $changed, $skipped, $fieldsChanged, array_values($days), 0 < $changed ? $accountIds : []);
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
            'accountId' => null !== $changes->accountId,
            'bookedOn' => null !== $changes->bookedOn,
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
            $edited = $current->edit(
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

            return $changes->moves() ? $edited->moved($changes->accountId, $changes->bookedOn) : $edited;
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
     * @param list<string>                      $accountIds accounts whose balance changed: identifiers only, never a delta
     */
    private function audit(WorkspaceContext $context, BulkEditInput $input, int $requested, int $changed, array $fields, array $skipped, ?string $categoryId, array $accountIds): void
    {
        $counts = [];
        foreach ($skipped as $reason) {
            $counts[$reason->value] = ($counts[$reason->value] ?? 0) + 1;
        }
        ksort($counts);
        ($this->recordAuditEvent)(new AuditEventRecord(
            $context->workspace, $context->actorId,
            $input->changes->moves() ? TransactionAuditEvents::BULK_MOVED : TransactionAuditEvents::BULK_EDITED,
            TransactionAuditEvents::BATCH_ENTITY, $this->uuidGenerator->generate(),
            AuditDiff::creation([
                'scope' => null === $input->ids ? 'FILTER' : 'IDS',
                'requested' => $requested,
                'changed' => $changed,
                'fields' => implode(',', $fields),
                'categoryId' => $categoryId,
                ...($input->changes->moves() ? ['accounts' => implode(',', $accountIds)] : []),
                'skips' => implode(',', array_map(static fn (string $reason, int $count): string => $reason.':'.$count, array_keys($counts), $counts)),
            ]),
        ));
    }
}
