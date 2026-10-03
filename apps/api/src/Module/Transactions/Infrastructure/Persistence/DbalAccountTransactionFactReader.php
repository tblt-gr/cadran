<?php

declare(strict_types=1);

namespace App\Module\Transactions\Infrastructure\Persistence;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Application\AccountTransactionFact;
use App\Module\Transactions\Application\AccountTransactionFactReader;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(AccountTransactionFactReader::class)]
final readonly class DbalAccountTransactionFactReader implements AccountTransactionFactReader
{
    public function __construct(private Connection $connection)
    {
    }

    public function between(WorkspaceScope $workspace, string $accountId, \DateTimeImmutable $from, \DateTimeImmutable $to, array $natures): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, nature, asset_code, amount_value, amount_scale, booked_on, state, voided_at FROM transaction_transactions
             WHERE workspace_id = :workspace_id AND account_id = :account_id AND booked_on >= :from_date AND booked_on <= :to_date AND nature IN (:natures)
             ORDER BY booked_on, id LIMIT :limit',
            [
                'workspace_id' => $workspace->id, 'account_id' => $accountId, 'from_date' => $from->format('Y-m-d'), 'to_date' => $to->format('Y-m-d'),
                'natures' => array_map(static fn ($nature): string => $nature->value, $natures), 'limit' => self::LIMIT + 1,
            ],
            ['natures' => ArrayParameterType::STRING, 'limit' => ParameterType::INTEGER],
        );

        return array_map(static function (array $row): AccountTransactionFact {
            $state = TransactionRow::text($row['state'] ?? null);

            return new AccountTransactionFact(
                TransactionRow::text($row['id'] ?? null),
                TransactionRow::text($row['nature'] ?? null),
                new AssetAmount(
                    DecimalValue::fromString(TransactionRow::decimal($row['amount_value'] ?? null, $row['amount_scale'] ?? null)),
                    AssetCode::fromString(TransactionRow::text($row['asset_code'] ?? null)),
                ),
                new \DateTimeImmutable(TransactionRow::text($row['booked_on'] ?? null), new \DateTimeZone('UTC')),
                $state,
                'VOIDED' === $state || null !== ($row['voided_at'] ?? null),
            );
        }, $rows);
    }
}
