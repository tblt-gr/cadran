<?php

declare(strict_types=1);

namespace App\Module\Transactions\Infrastructure\Persistence;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Application\MonthlyTransferPairFact;
use App\Module\Transactions\Application\MonthlyTransferPairReader;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(MonthlyTransferPairReader::class)]
final readonly class DbalMonthlyTransferPairReader implements MonthlyTransferPairReader
{
    public function __construct(private Connection $connection)
    {
    }

    public function read(
        WorkspaceScope $workspace,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int $limit,
    ): array {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT x.id AS transfer_id, x.source_transaction_id, x.target_transaction_id, x.voided_at AS transfer_voided_at, '
            .'s.id AS source_id, s.account_id AS source_account_id, s.amount_value::text AS source_amount, s.amount_scale AS source_scale, '
            .'s.asset_code AS source_asset, s.state AS source_state, s.booked_on AS source_booked_on, '
            .'d.id AS target_id, d.account_id AS target_account_id, d.amount_value::text AS target_amount, d.amount_scale AS target_scale, '
            .'d.asset_code AS target_asset, d.state AS target_state, d.booked_on AS target_booked_on '
            .'FROM transaction_transfers x '
            .'LEFT JOIN transaction_transactions s ON s.workspace_id = :workspace_id AND s.id = x.source_transaction_id '
            .'LEFT JOIN transaction_transactions d ON d.workspace_id = :workspace_id AND d.id = x.target_transaction_id '
            .'WHERE x.workspace_id = :workspace_id '
            .'AND ((s.booked_on BETWEEN :from_date AND :to_date) OR (d.booked_on BETWEEN :from_date AND :to_date)) '
            .'ORDER BY x.id LIMIT :limit',
            [
                'workspace_id' => $workspace->id,
                'from_date' => $from->format('Y-m-d'),
                'to_date' => $to->format('Y-m-d'),
                'limit' => $limit,
            ],
            ['limit' => ParameterType::INTEGER],
        );

        return array_map(self::fact(...), $rows);
    }

    /** @param array<string, mixed> $row */
    private static function fact(array $row): MonthlyTransferPairFact
    {
        return new MonthlyTransferPairFact(
            self::required($row, 'transfer_id'),
            self::optional($row, 'source_id'),
            self::optional($row, 'target_id'),
            self::optional($row, 'source_account_id'),
            self::optional($row, 'target_account_id'),
            self::decimal($row, 'source_amount', 'source_scale'),
            self::decimal($row, 'target_amount', 'target_scale'),
            self::asset($row, 'source_asset'),
            self::asset($row, 'target_asset'),
            self::optional($row, 'source_state'),
            self::optional($row, 'target_state'),
            self::optional($row, 'source_booked_on'),
            self::optional($row, 'target_booked_on'),
            null !== ($row['transfer_voided_at'] ?? null),
        );
    }

    /** @param array<string, mixed> $row */
    private static function decimal(array $row, string $key, string $scaleKey): ?DecimalValue
    {
        $value = self::optional($row, $key);

        if (null === $value) {
            return null;
        }
        $scale = (int) self::required($row, $scaleKey);
        $parts = explode('.', self::canonicalNumeric($value), 2);
        $literal = 0 === $scale ? $parts[0] : $parts[0].'.'.str_pad($parts[1] ?? '', $scale, '0');

        return DecimalValue::fromString($literal);
    }

    /** @param array<string, mixed> $row */
    private static function asset(array $row, string $key): ?AssetCode
    {
        $value = self::optional($row, $key);

        return null === $value ? null : AssetCode::fromString($value);
    }

    /** @param array<string, mixed> $row */
    private static function required(array $row, string $key): string
    {
        return self::optional($row, $key) ?? throw new \UnexpectedValueException(sprintf('Missing %s in a transfer pair row.', $key));
    }

    /** @param array<string, mixed> $row */
    private static function optional(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if (null === $value) {
            return null;
        }
        if (!is_scalar($value)) {
            throw new \UnexpectedValueException(sprintf('Expected scalar %s in a transfer pair row.', $key));
        }

        return (string) $value;
    }

    private static function canonicalNumeric(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}
