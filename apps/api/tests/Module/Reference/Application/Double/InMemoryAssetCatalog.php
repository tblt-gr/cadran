<?php

declare(strict_types=1);

namespace App\Tests\Module\Reference\Application\Double;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\RoundingMode;
use App\Module\Reference\Application\AssetCatalog;
use App\Module\Reference\Domain\Asset;
use App\Module\Reference\Domain\AssetKind;
use App\Module\Reference\Domain\AssetPrecision;

/**
 * The catalogue without PostgreSQL, so use-case tests exercise paging and
 * lookup rules rather than SQL. The seeded shape is deliberately plain: the
 * real precisions are asserted against the migration itself.
 */
final class InMemoryAssetCatalog implements AssetCatalog
{
    /** @var list<Asset> */
    private readonly array $assets;

    /**
     * @param list<Asset> $assets
     */
    private function __construct(array $assets)
    {
        // Ordered by code, like the real catalogue. A double that paged in
        // insertion order would let a test assert a sequence PostgreSQL can
        // never return, and AssetCatalog::readPage() promises a stable order.
        usort($assets, static fn (Asset $left, Asset $right): int => strcmp($left->code->toString(), $right->code->toString()));

        $this->assets = $assets;
    }

    public static function withCodes(string ...$codes): self
    {
        return new self(self::assets(AssetKind::FIAT, $codes));
    }

    public function andCryptoCodes(string ...$codes): self
    {
        return new self([...$this->assets, ...self::assets(AssetKind::CRYPTO, $codes)]);
    }

    public function findByCode(AssetCode $code): ?Asset
    {
        foreach ($this->assets as $asset) {
            if ($asset->code->equals($code)) {
                return $asset;
            }
        }

        return null;
    }

    public function readPage(int $limit, int $offset): array
    {
        return array_slice($this->assets, $offset, $limit);
    }

    public function count(): int
    {
        return count($this->assets);
    }

    /**
     * @param array<array-key, string> $codes
     *
     * @return list<Asset>
     */
    private static function assets(AssetKind $kind, array $codes): array
    {
        return array_values(array_map(
            static fn (string $code): Asset => new Asset(
                code: AssetCode::fromString($code),
                kind: $kind,
                displayName: $code,
                precision: new AssetPrecision(storage: 8, display: 2),
                roundingMode: RoundingMode::HALF_UP,
            ),
            $codes,
        ));
    }
}
