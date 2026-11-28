<?php

declare(strict_types=1);

namespace App\Module\Reference\Infrastructure\Persistence;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\RoundingMode;
use App\Module\Foundation\Infrastructure\Persistence\DbalValue;
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
 * offers no write path - assets change through a migration.
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
        return (int) DbalValue::text($this->connection->fetchOne('SELECT count(*) FROM reference_assets'));
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): Asset
    {
        return new Asset(
            code: AssetCode::fromString(DbalValue::text($row['code'] ?? null)),
            kind: AssetKind::from(DbalValue::text($row['kind'] ?? null)),
            displayName: DbalValue::text($row['display_name'] ?? null),
            precision: new AssetPrecision(
                storage: (int) DbalValue::text($row['storage_precision'] ?? null),
                display: (int) DbalValue::text($row['display_precision'] ?? null),
            ),
            roundingMode: RoundingMode::from(DbalValue::text($row['rounding_mode'] ?? null)),
        );
    }
}
