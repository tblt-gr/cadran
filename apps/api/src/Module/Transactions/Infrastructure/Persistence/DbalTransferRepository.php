<?php

declare(strict_types=1);

namespace App\Module\Transactions\Infrastructure\Persistence;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\Transfer;
use App\Module\Transactions\Domain\TransferRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(TransferRepository::class)]
final readonly class DbalTransferRepository implements TransferRepository
{
    private const string COLUMNS = 'id, workspace_id, source_transaction_id, target_transaction_id, fee_transaction_id, exchange_rate, version, created_at, updated_at, voided_at';

    public function __construct(private Connection $connection)
    {
    }

    public function find(WorkspaceScope $workspace, string $id): ?Transfer
    {
        return $this->one($workspace, ['id' => $id], false);
    }

    public function findForUpdate(WorkspaceScope $workspace, string $id): ?Transfer
    {
        return $this->one($workspace, ['id' => $id], true);
    }

    public function findByLegTransactionId(WorkspaceScope $workspace, string $transactionId): ?Transfer
    {
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.' FROM transaction_transfers WHERE workspace_id = :workspace_id'
            .' AND (source_transaction_id = :transaction_id OR target_transaction_id = :transaction_id OR fee_transaction_id = :transaction_id)',
            ['workspace_id' => $workspace->id, 'transaction_id' => $transactionId],
        );

        return false === $row ? null : self::hydrate($row, $workspace);
    }

    public function markersForLegs(WorkspaceScope $workspace, array $transactionIds): array
    {
        if ([] === $transactionIds) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, source_transaction_id, target_transaction_id, fee_transaction_id'
            .' FROM transaction_transfers WHERE workspace_id = :workspace_id'
            .' AND (source_transaction_id IN (:transaction_ids)'
            .' OR target_transaction_id IN (:transaction_ids)'
            .' OR fee_transaction_id IN (:transaction_ids))',
            ['workspace_id' => $workspace->id, 'transaction_ids' => $transactionIds],
            ['transaction_ids' => ArrayParameterType::STRING],
        );

        $markers = [];
        foreach ($rows as $row) {
            $transferId = TransactionRow::text($row['id'] ?? null);
            foreach (['source_transaction_id', 'target_transaction_id', 'fee_transaction_id'] as $column) {
                $legId = $row[$column] ?? null;
                if (null !== $legId) {
                    $markers[TransactionRow::text($legId)] = $transferId;
                }
            }
        }

        return $markers;
    }

    public function add(Transfer $transfer): void
    {
        $this->connection->insert('transaction_transfers', [
            'id' => $transfer->id,
            'workspace_id' => $transfer->workspace->id,
            'source_transaction_id' => $transfer->sourceTransactionId,
            'target_transaction_id' => $transfer->targetTransactionId,
            'created_at' => $transfer->createdAt->format('Y-m-d H:i:s.uP'),
            ...self::mutableColumns($transfer),
        ]);
    }

    public function update(Transfer $transfer, int $expectedVersion): bool
    {
        return 1 === (int) $this->connection->update(
            'transaction_transfers',
            self::mutableColumns($transfer),
            ['workspace_id' => $transfer->workspace->id, 'id' => $transfer->id, 'version' => $expectedVersion],
        );
    }

    /** @param array<string, mixed> $criteria */
    private function one(WorkspaceScope $workspace, array $criteria, bool $forUpdate): ?Transfer
    {
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.' FROM transaction_transfers WHERE workspace_id = :workspace_id AND id = :id'.($forUpdate ? ' FOR UPDATE' : ''),
            ['workspace_id' => $workspace->id, ...$criteria],
        );

        return false === $row ? null : self::hydrate($row, $workspace);
    }

    /** @return array<string, mixed> */
    private static function mutableColumns(Transfer $transfer): array
    {
        return [
            'fee_transaction_id' => $transfer->feeTransactionId,
            'exchange_rate' => $transfer->exchangeRate?->toString(),
            'version' => $transfer->version,
            'updated_at' => $transfer->updatedAt->format('Y-m-d H:i:s.uP'),
            'voided_at' => $transfer->voidedAt?->format('Y-m-d H:i:s.uP'),
        ];
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row, WorkspaceScope $workspace): Transfer
    {
        if ($workspace->id !== TransactionRow::text($row['workspace_id'] ?? null)) {
            throw new \UnexpectedValueException('A transfer row escaped its requested workspace.');
        }

        $rate = null === ($row['exchange_rate'] ?? null) ? null : TransactionRow::text($row['exchange_rate']);

        return new Transfer(
            id: TransactionRow::text($row['id'] ?? null),
            workspace: $workspace,
            sourceTransactionId: TransactionRow::text($row['source_transaction_id'] ?? null),
            targetTransactionId: TransactionRow::text($row['target_transaction_id'] ?? null),
            feeTransactionId: null === ($row['fee_transaction_id'] ?? null) ? null : TransactionRow::text($row['fee_transaction_id']),
            exchangeRate: null === $rate ? null : DecimalValue::fromString(self::trimNumeric($rate)),
            version: (int) TransactionRow::text($row['version'] ?? null),
            createdAt: new \DateTimeImmutable(TransactionRow::text($row['created_at'] ?? null)),
            updatedAt: new \DateTimeImmutable(TransactionRow::text($row['updated_at'] ?? null)),
            voidedAt: null === ($row['voided_at'] ?? null) ? null : new \DateTimeImmutable(TransactionRow::text($row['voided_at'])),
        );
    }

    private static function trimNumeric(string $numeric): string
    {
        if (!str_contains($numeric, '.')) {
            return $numeric;
        }

        return rtrim(rtrim($numeric, '0'), '.');
    }
}
