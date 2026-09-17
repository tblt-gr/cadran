<?php

declare(strict_types=1);

namespace App\Module\Transactions\Infrastructure\Persistence;

use App\Module\Categories\Application\CategoryClassificationCounter;
use App\Module\Categories\Domain\AnalyticAxis;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\CategorizationOrigin;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionFilters;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionPosition;
use App\Module\Transactions\Domain\TransactionRepository;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\TransactionSplit;
use App\Module\Transactions\Domain\TransactionState;
use App\Module\Transactions\Domain\TransactionWatermark;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(TransactionRepository::class)]
final readonly class DbalTransactionRepository implements CategoryClassificationCounter, TransactionRepository
{
    private const string COLUMNS = 'id, workspace_id, account_id, asset_code, amount_value, amount_scale, original_amount_value, original_amount_scale, original_asset_code, exchange_rate, state, nature, source, source_ref, booked_on, value_on, authorized_on, raw_label, counterparty, note, payment_method, mcc, masked_card, bank_reference, version, created_at, updated_at, voided_at, last_editor_id';

    public function __construct(private Connection $connection)
    {
    }

    public function find(WorkspaceScope $workspace, string $id): ?Transaction
    {
        return $this->one($workspace, $id, false);
    }

    public function findForUpdate(WorkspaceScope $workspace, string $id): ?Transaction
    {
        return $this->one($workspace, $id, true);
    }

    public function search(
        WorkspaceScope $workspace,
        TransactionFilters $filters,
        int $limit,
        ?TransactionPosition $after,
    ): array {
        // The three EXISTS fragments below carry their own bound :workspace_id
        // predicate rather than comparing s.workspace_id to t.workspace_id: an
        // EXISTS subquery must never let the outer, already-scoped table vouch
        // for the inner one. They live in this single unconditional
        // concatenation — rather than each behind its own `if ($sql .= ...)` —
        // so the static workspace-scope guard (scripts/check-workspace-scope.php)
        // can resolve the full query text; a scoped table named only inside a
        // conditionally-appended statement is invisible to its analysis.
        $sql = 'SELECT '.self::COLUMNS.' FROM transaction_transactions t WHERE t.workspace_id = :workspace_id'
            .($filters->categorizationNone
                ? ' AND NOT EXISTS (SELECT 1 FROM transaction_splits s WHERE s.workspace_id = :workspace_id AND s.transaction_id = t.id)'
                : '')
            .([] !== $filters->categoryIds
                ? ' AND EXISTS (SELECT 1 FROM transaction_splits s WHERE s.workspace_id = :workspace_id AND s.transaction_id = t.id AND s.category_id IN (:category_ids))'
                : '')
            .([] !== $filters->axes
                ? ' AND EXISTS (SELECT 1 FROM transaction_splits s, jsonb_array_elements_text(s.analytic_axes) elem(value) WHERE s.workspace_id = :workspace_id AND s.transaction_id = t.id AND elem.value IN (:axes))'
                : '');
        $parameters = ['workspace_id' => $workspace->id, 'limit' => $limit];
        $types = ['limit' => ParameterType::INTEGER];

        // The default (no explicit state filter) state scope is a business
        // decision the caller has already resolved into a concrete, non-empty
        // list before reaching this repository: PENDING and BOOKED, plus
        // VOIDED only when includeVoided is set. An empty list here means no
        // restriction at all, so a caller can name every state explicitly.
        if ([] !== $filters->states) {
            $sql .= ' AND t.state IN (:states)';
            $parameters['states'] = array_map(static fn (TransactionState $state): string => $state->value, $filters->states);
            $types['states'] = ArrayParameterType::STRING;
        }
        if (null !== $filters->from) {
            $sql .= ' AND t.booked_on >= :from_date';
            $parameters['from_date'] = $filters->from->format('Y-m-d');
        }
        if (null !== $filters->to) {
            $sql .= ' AND t.booked_on <= :to_date';
            $parameters['to_date'] = $filters->to->format('Y-m-d');
        }
        if ([] !== $filters->accountIds) {
            $sql .= ' AND t.account_id IN (:account_ids)';
            $parameters['account_ids'] = $filters->accountIds;
            $types['account_ids'] = ArrayParameterType::STRING;
        }
        if ([] !== $filters->natures) {
            $sql .= ' AND t.nature IN (:natures)';
            $parameters['natures'] = array_map(static fn (TransactionNature $nature): string => $nature->value, $filters->natures);
            $types['natures'] = ArrayParameterType::STRING;
        }
        if ([] !== $filters->sources) {
            $sql .= ' AND t.source IN (:sources)';
            $parameters['sources'] = array_map(static fn (TransactionSource $source): string => $source->value, $filters->sources);
            $types['sources'] = ArrayParameterType::STRING;
        }
        if ([] !== $filters->categoryIds) {
            $parameters['category_ids'] = $filters->categoryIds;
            $types['category_ids'] = ArrayParameterType::STRING;
        }
        if ([] !== $filters->axes) {
            $parameters['axes'] = array_map(static fn (AnalyticAxis $axis): string => $axis->value, $filters->axes);
            $types['axes'] = ArrayParameterType::STRING;
        }
        if (null !== $filters->minAmount || null !== $filters->maxAmount) {
            $sql .= ' AND t.asset_code = :amount_asset_code';
            $parameters['amount_asset_code'] = $filters->assetCode?->toString();
        }
        if (null !== $filters->minAmount) {
            $sql .= ' AND t.amount_value >= :min_amount';
            $parameters['min_amount'] = $filters->minAmount->toString();
        }
        if (null !== $filters->maxAmount) {
            $sql .= ' AND t.amount_value <= :max_amount';
            $parameters['max_amount'] = $filters->maxAmount->toString();
        }
        if (null !== $filters->q) {
            // Simultaneous strtr: the backslash the escape introduces is never
            // re-escaped by the % or _ replacement that follows it.
            // normalized_label is a generated column equal to
            // lower(btrim(raw_label)): any row lower(raw_label) would match is
            // already matched by normalized_label, so there is no separate
            // raw_label branch (and no index needed for one).
            $escaped = strtr(mb_strtolower($filters->q, 'UTF-8'), ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
            $sql .= ' AND (t.normalized_label LIKE :pattern ESCAPE \'\\\' '
                .'OR lower(t.counterparty) LIKE :pattern ESCAPE \'\\\')';
            $parameters['pattern'] = '%'.$escaped.'%';
        }
        if (null !== $after) {
            $sql .= ' AND (t.booked_on, t.id) < (:booked_on, :cursor_id)';
            $parameters['booked_on'] = $after->bookedOn->format('Y-m-d');
            $parameters['cursor_id'] = $after->id;
        }
        $sql .= ' ORDER BY t.booked_on DESC, t.id DESC LIMIT :limit';
        $rows = $this->connection->fetchAllAssociative($sql, $parameters, $types);

        return $this->hydrateMany($workspace, $rows);
    }

    public function watermark(WorkspaceScope $workspace): ?TransactionWatermark
    {
        $row = $this->connection->fetchAssociative(
            'SELECT updated_at, id FROM transaction_transactions WHERE workspace_id = :workspace_id ORDER BY updated_at DESC, id DESC LIMIT 1',
            ['workspace_id' => $workspace->id],
        );

        return false === $row ? null : new TransactionWatermark(
            new \DateTimeImmutable(TransactionRow::text($row['updated_at'] ?? null)),
            TransactionRow::text($row['id'] ?? null),
        );
    }

    public function countForCategory(WorkspaceScope $workspace, string $categoryId): int
    {
        return (int) TransactionRow::text($this->connection->fetchOne(
            'SELECT count(*) FROM transaction_splits WHERE workspace_id = :workspace_id AND category_id = :category_id',
            ['workspace_id' => $workspace->id, 'category_id' => $categoryId],
        ));
    }

    public function listForCategorization(WorkspaceScope $workspace, \DateTimeImmutable $from, \DateTimeImmutable $to, int $limit, bool $lock): array
    {
        $sql = 'SELECT '.self::COLUMNS.' FROM transaction_transactions t WHERE t.workspace_id = :workspace_id '
            ."AND t.booked_on BETWEEN :from_date AND :to_date AND t.state NOT IN ('VOIDED', 'REJECTED') "
            ."AND t.nature IN ('INCOME', 'EXPENSE', 'FEE', 'ADJUSTMENT') "
            .'AND NOT EXISTS (SELECT 1 FROM transaction_transfers x WHERE x.workspace_id = :workspace_id AND (x.source_transaction_id = t.id OR x.target_transaction_id = t.id OR x.fee_transaction_id = t.id)) '
            // A refund copies its original's splits when it is recorded, so recategorising a
            // refunded original would make the refund reduce another category than the expense.
            .'AND NOT EXISTS (SELECT 1 FROM transaction_refunds r JOIN transaction_transactions rt ON rt.workspace_id = r.workspace_id AND rt.id = r.refund_transaction_id '
            ."WHERE r.workspace_id = :workspace_id AND rt.workspace_id = :workspace_id AND r.original_transaction_id = t.id AND rt.state NOT IN ('VOIDED', 'REJECTED')) "
            .'ORDER BY t.id LIMIT :limit';
        if ($lock) {
            $sql .= ' FOR UPDATE OF t';
        }
        $rows = $this->connection->fetchAllAssociative(
            $sql,
            ['workspace_id' => $workspace->id, 'from_date' => $from->format('Y-m-d'), 'to_date' => $to->format('Y-m-d'), 'limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        );

        return $this->hydrateMany($workspace, $rows);
    }

    public function add(Transaction $transaction): void
    {
        $this->connection->insert('transaction_transactions', [
            'id' => $transaction->id,
            'workspace_id' => $transaction->workspace->id,
            'account_id' => $transaction->accountId,
            'asset_code' => $transaction->amount->asset->toString(),
            'original_amount_value' => $transaction->originalAmount?->value->toString(),
            'original_amount_scale' => $transaction->originalAmount?->value->scale(),
            'original_asset_code' => $transaction->originalAmount?->asset->toString(),
            'exchange_rate' => $transaction->exchangeRate?->toString(),
            'source' => $transaction->source->value,
            'source_ref' => $transaction->sourceRef,
            'raw_label' => $transaction->rawLabel,
            'created_at' => $transaction->createdAt->format('Y-m-d H:i:s.uP'),
            ...TransactionRow::mutableColumns($transaction),
        ]);
        $this->replaceSplits($transaction);
    }

    public function update(Transaction $transaction, int $expectedVersion): bool
    {
        $written = 1 === (int) $this->connection->update(
            'transaction_transactions',
            [
                'raw_label' => $transaction->rawLabel,
                ...TransactionRow::mutableColumns($transaction),
            ],
            ['workspace_id' => $transaction->workspace->id, 'id' => $transaction->id, 'version' => $expectedVersion],
        );
        if ($written) {
            $this->replaceSplits($transaction);
        }

        return $written;
    }

    private function one(WorkspaceScope $workspace, string $id, bool $forUpdate): ?Transaction
    {
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.' FROM transaction_transactions WHERE workspace_id = :workspace_id AND id = :id'.($forUpdate ? ' FOR UPDATE' : ''),
            ['workspace_id' => $workspace->id, 'id' => $id],
        );

        return false === $row ? null : TransactionRow::hydrate($row, $workspace, $this->splits($workspace, [$id])[$id] ?? []);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<Transaction>
     */
    private function hydrateMany(WorkspaceScope $workspace, array $rows): array
    {
        $ids = array_map(static fn (array $row): string => TransactionRow::text($row['id'] ?? null), $rows);
        $splits = $this->splits($workspace, $ids);

        return array_map(
            static fn (array $row): Transaction => TransactionRow::hydrate(
                $row,
                $workspace,
                $splits[TransactionRow::text($row['id'] ?? null)] ?? [],
            ),
            $rows,
        );
    }

    /**
     * @param list<string> $transactionIds
     *
     * @return array<string, list<TransactionSplit>>
     */
    private function splits(WorkspaceScope $workspace, array $transactionIds): array
    {
        if ([] === $transactionIds) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, workspace_id, transaction_id, category_id, amount_value, amount_scale, asset_code, analytic_axes, note, created_at, position, categorization_origin, categorization_rule_id'
            .' FROM transaction_splits WHERE workspace_id = :workspace_id AND transaction_id IN (:transaction_ids) ORDER BY transaction_id, position',
            ['workspace_id' => $workspace->id, 'transaction_ids' => $transactionIds],
            ['transaction_ids' => ArrayParameterType::STRING],
        );
        $grouped = [];
        foreach ($rows as $row) {
            $transactionId = TransactionRow::text($row['transaction_id'] ?? null);
            $axes = json_decode(TransactionRow::text($row['analytic_axes'] ?? null), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($axes)) {
                throw new \UnexpectedValueException('Expected a transaction split axes to be an array.');
            }
            $grouped[$transactionId][] = new TransactionSplit(
                id: TransactionRow::text($row['id'] ?? null),
                workspace: $workspace,
                transactionId: $transactionId,
                categoryId: TransactionRow::text($row['category_id'] ?? null),
                amount: new AssetAmount(
                    DecimalValue::fromString(TransactionRow::decimal($row['amount_value'] ?? null, $row['amount_scale'] ?? null)),
                    AssetCode::fromString(TransactionRow::text($row['asset_code'] ?? null)),
                ),
                analyticAxes: array_map(
                    static fn (mixed $axis): AnalyticAxis => AnalyticAxis::from(TransactionRow::text($axis)),
                    array_values($axes),
                ),
                note: null === ($row['note'] ?? null) ? null : TransactionRow::text($row['note']),
                createdAt: new \DateTimeImmutable(TransactionRow::text($row['created_at'] ?? null)),
                position: (int) TransactionRow::text($row['position'] ?? null),
                origin: CategorizationOrigin::from(TransactionRow::text($row['categorization_origin'] ?? null)),
                ruleId: null === ($row['categorization_rule_id'] ?? null) ? null : TransactionRow::text($row['categorization_rule_id']),
            );
        }

        return $grouped;
    }

    /**
     * Splits are replaced as a whole under the row lock the caller already
     * holds on the transaction ({@see findForUpdate}), so a concurrent second
     * writer either sees this result in full or fails on a stale version
     * instead of interleaving with a partial delete-then-insert.
     */
    private function replaceSplits(Transaction $transaction): void
    {
        $this->connection->delete('transaction_splits', [
            'workspace_id' => $transaction->workspace->id,
            'transaction_id' => $transaction->id,
        ]);
        foreach ($transaction->splits as $split) {
            $this->connection->insert('transaction_splits', [
                'id' => $split->id,
                'workspace_id' => $split->workspace->id,
                'transaction_id' => $split->transactionId,
                'category_id' => $split->categoryId,
                'amount_value' => $split->amount->value->toString(),
                'amount_scale' => $split->amount->value->scale(),
                'asset_code' => $split->amount->asset->toString(),
                'analytic_axes' => json_encode(
                    array_map(static fn (AnalyticAxis $axis): string => $axis->value, $split->analyticAxes),
                    JSON_THROW_ON_ERROR,
                ),
                'note' => $split->note,
                'created_at' => $split->createdAt->format('Y-m-d H:i:s.uP'),
                'position' => $split->position,
                'categorization_origin' => $split->origin->value,
                'categorization_rule_id' => $split->ruleId,
            ]);
        }
    }
}
