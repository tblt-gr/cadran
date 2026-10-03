<?php

declare(strict_types=1);

namespace App\Module\Transactions\Infrastructure\Persistence;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Application\TransferLegFact;
use App\Module\Transactions\Application\TransferLegReader;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(TransferLegReader::class)]
final readonly class DbalTransferLegReader implements TransferLegReader
{
    private const string COLUMNS = 'id, account_id, asset_code, amount_value, amount_scale, booked_on, state, voided_at, raw_label';

    public function __construct(private Connection $connection)
    {
    }

    public function find(WorkspaceScope $workspace, string $transactionId): ?TransferLegFact
    {
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS." FROM transaction_transactions WHERE workspace_id = :workspace_id AND id = :id AND nature = 'TRANSFER'",
            ['workspace_id' => $workspace->id, 'id' => $transactionId],
        );

        return false === $row ? null : self::fact($row);
    }

    public function findMany(WorkspaceScope $workspace, array $transactionIds): array
    {
        if ([] === $transactionIds) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::COLUMNS." FROM transaction_transactions WHERE workspace_id = :workspace_id AND id IN (:ids) AND nature = 'TRANSFER'",
            ['workspace_id' => $workspace->id, 'ids' => $transactionIds],
            ['ids' => ArrayParameterType::STRING],
        );

        return array_map(self::fact(...), $rows);
    }

    public function recent(WorkspaceScope $workspace, string $accountId, string $direction, int $limit): array
    {
        $sign = 'IN' === $direction ? '> 0' : '< 0';
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::COLUMNS." FROM transaction_transactions WHERE workspace_id = :workspace_id AND account_id = :account_id AND nature = 'TRANSFER' AND state = 'BOOKED' AND voided_at IS NULL AND amount_value ".$sign.' ORDER BY booked_on DESC, id DESC LIMIT :limit',
            ['workspace_id' => $workspace->id, 'account_id' => $accountId, 'limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        );

        return array_map(self::fact(...), $rows);
    }

    /** @param array<string, mixed> $row */
    private static function fact(array $row): TransferLegFact
    {
        $state = TransactionRow::text($row['state'] ?? null);

        return new TransferLegFact(
            TransactionRow::text($row['id'] ?? null),
            TransactionRow::text($row['account_id'] ?? null),
            new AssetAmount(
                DecimalValue::fromString(TransactionRow::decimal($row['amount_value'] ?? null, $row['amount_scale'] ?? null)),
                AssetCode::fromString(TransactionRow::text($row['asset_code'] ?? null)),
            ),
            new \DateTimeImmutable(TransactionRow::text($row['booked_on'] ?? null), new \DateTimeZone('UTC')),
            $state,
            'VOIDED' === $state || null !== ($row['voided_at'] ?? null),
            null === ($row['raw_label'] ?? null) ? null : TransactionRow::text($row['raw_label']),
        );
    }
}
