<?php

declare(strict_types=1);

namespace App\Module\Transactions\Infrastructure\Persistence;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Application\BookedMovementFact;
use App\Module\Transactions\Application\BookedMovementReader;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(BookedMovementReader::class)]
final readonly class DbalBookedMovementReader implements BookedMovementReader
{
    public function __construct(private Connection $connection)
    {
    }

    public function between(WorkspaceScope $workspace, \DateTimeImmutable $from, \DateTimeImmutable $to, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, account_id, nature, asset_code, amount_value, amount_scale FROM transaction_transactions
             WHERE workspace_id = :workspace_id AND state = 'BOOKED' AND booked_on >= :from_date AND booked_on <= :to_date
             ORDER BY booked_on, id LIMIT :limit",
            ['workspace_id' => $workspace->id, 'from_date' => $from->format('Y-m-d'), 'to_date' => $to->format('Y-m-d'), 'limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $row): BookedMovementFact => new BookedMovementFact(
            TransactionRow::text($row['id'] ?? null),
            TransactionRow::text($row['account_id'] ?? null),
            TransactionRow::text($row['nature'] ?? null),
            new AssetAmount(
                DecimalValue::fromString(TransactionRow::decimal($row['amount_value'] ?? null, $row['amount_scale'] ?? null)),
                AssetCode::fromString(TransactionRow::text($row['asset_code'] ?? null)),
            ),
        ), $rows);
    }
}
