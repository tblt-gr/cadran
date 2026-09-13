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
use App\Module\Transactions\Domain\TransactionPosition;
use App\Module\Transactions\Domain\TransactionRepository;
use App\Module\Transactions\Domain\TransactionSplit;
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

    public function list(
        WorkspaceScope $workspace,
        ?string $accountId,
        bool $includeVoided,
        int $limit,
        ?TransactionPosition $after,
        bool $uncategorized = false,
    ): array {
        $sql = 'SELECT '.self::COLUMNS.' FROM transaction_transactions WHERE workspace_id = :workspace_id';
        $parameters = ['workspace_id' => $workspace->id, 'limit' => $limit];
        $types = ['limit' => ParameterType::INTEGER];
        if ($uncategorized || !$includeVoided) {
            $sql .= " AND state <> 'VOIDED'";
        }
        if ($uncategorized) {
            $sql .= ' AND NOT EXISTS ('
                .'SELECT 1 FROM transaction_splits s '
                .'WHERE s.workspace_id = transaction_transactions.workspace_id '
                .'AND s.transaction_id = transaction_transactions.id'
                .')';
        }
        if (null !== $accountId) {
            $sql .= ' AND account_id = :account_id';
            $parameters['account_id'] = $accountId;
        }
        if (null !== $after) {
            $sql .= ' AND (booked_on, id) < (:booked_on, :cursor_id)';
            $parameters['booked_on'] = $after->bookedOn->format('Y-m-d');
            $parameters['cursor_id'] = $after->id;
        }
        $sql .= ' ORDER BY booked_on DESC, id DESC LIMIT :limit';
        $rows = $this->connection->fetchAllAssociative($sql, $parameters, $types);

        return $this->hydrateMany($workspace, $rows);
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
