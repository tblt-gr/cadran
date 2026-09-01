<?php

declare(strict_types=1);

namespace App\Module\Reference\Infrastructure\Persistence;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\RoundingMode;
use App\Module\Reference\Application\AssetCatalog;
use App\Module\Reference\Domain\Asset;
use App\Module\Reference\Domain\AssetKind;
use App\Module\Reference\Domain\AssetPrecision;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Read-only access to reference_assets. The table carries no workspace_id: it
 * is a global system reference, identical for every caller, and this class
 * offers no write path — assets change through a migration.
 */
#[AsAlias(AssetCatalog::class)]
final readonly class DbalAssetCatalog implements AssetCatalog
{
    private const string COLUMNS = 'code, kind, display_name, storage_precision, display_precision, rounding_mode';

    public function __construct(private Connection $connection)
    {
    }

    public function findByCode(AssetCode $code): ?Asset
    {
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.' FROM reference_assets WHERE code = :code',
            ['code' => $code->toString()],
        );

        return false === $row ? null : self::hydrate($row);
    }

    public function readPage(int $limit, int $offset): array
    {
        // The code is the primary key, so ordering on it is both stable and
        // free: two identical requests always return the same page.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::COLUMNS.' FROM reference_assets ORDER BY code LIMIT :limit OFFSET :offset',
            ['limit' => $limit, 'offset' => $offset],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return array_map(self::hydrate(...), $rows);
    }

    public function count(): int
    {
        return (int) self::asString($this->connection->fetchOne('SELECT count(*) FROM reference_assets'));
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): Asset
    {
        return new Asset(
            code: AssetCode::fromString(self::asString($row['code'] ?? null)),
            kind: AssetKind::from(self::asString($row['kind'] ?? null)),
            displayName: self::asString($row['display_name'] ?? null),
            precision: new AssetPrecision(
                storage: (int) self::asString($row['storage_precision'] ?? null),
                display: (int) self::asString($row['display_precision'] ?? null),
            ),
            roundingMode: RoundingMode::from(self::asString($row['rounding_mode'] ?? null)),
        );
    }

    private static function asString(mixed $value): string
    {
        if (!is_scalar($value)) {
            throw new \UnexpectedValueException('Expected a scalar database value.');
        }

        return (string) $value;
    }
}
