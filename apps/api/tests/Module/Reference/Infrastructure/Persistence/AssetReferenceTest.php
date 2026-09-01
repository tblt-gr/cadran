<?php

declare(strict_types=1);

namespace App\Tests\Module\Reference\Infrastructure\Persistence;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\RoundingMode;
use App\Module\Reference\Application\AssetCatalog;
use App\Module\Reference\Domain\AssetKind;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The asset reference against the migrated PostgreSQL schema: the rows the
 * migration actually seeds, the order the catalogue reads them in, and the
 * constraints that stop an impossible precision from ever being stored.
 */
final class AssetReferenceTest extends KernelTestCase
{
    /** SQLSTATE of a PostgreSQL check-constraint violation. */
    private const string CHECK_VIOLATION = '23514';

    private Connection $connection;
    private AssetCatalog $catalog;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();

        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;

        $catalog = self::getContainer()->get(AssetCatalog::class);
        self::assertInstanceOf(AssetCatalog::class, $catalog);
        $this->catalog = $catalog;
    }

    public function testTheMigrationSeedsTheDocumentedReference(): void
    {
        $seeded = [];
        foreach ($this->catalog->readPage(100, 0) as $asset) {
            $seeded[$asset->code->toString()] = [
                $asset->kind->value,
                $asset->precision->storage,
                $asset->precision->display,
                $asset->roundingMode->value,
            ];
        }

        self::assertSame([
            'BTC' => ['CRYPTO', 8, 8, 'HALF_UP'],
            'CHF' => ['FIAT', 8, 2, 'HALF_UP'],
            'ETH' => ['CRYPTO', 18, 8, 'HALF_UP'],
            'EUR' => ['FIAT', 8, 2, 'HALF_UP'],
            'GBP' => ['FIAT', 8, 2, 'HALF_UP'],
            'JPY' => ['FIAT', 8, 0, 'HALF_UP'],
            'USD' => ['FIAT', 8, 2, 'HALF_UP'],
        ], $seeded);
    }

    public function testTheCatalogueReadsInAStableOrderAndPagesInside(): void
    {
        $first = $this->catalog->readPage(2, 0);
        $second = $this->catalog->readPage(2, 2);

        self::assertSame(['BTC', 'CHF'], self::codesOf($first));
        self::assertSame(['ETH', 'EUR'], self::codesOf($second));
        self::assertSame(7, $this->catalog->count());
    }

    public function testAKnownCodeIsHydratedWithItsDomainTypes(): void
    {
        $euro = $this->catalog->findByCode(AssetCode::fromString('EUR'));

        self::assertNotNull($euro);
        self::assertSame(AssetKind::FIAT, $euro->kind);
        self::assertSame(RoundingMode::HALF_UP, $euro->roundingMode);
        self::assertSame('Euro', $euro->displayName);
        self::assertSame('0.01', $euro->displayStep()->value->toString());
    }

    public function testAnUnknownCodeReadsNothing(): void
    {
        self::assertNull($this->catalog->findByCode(AssetCode::fromString('XAU')));
    }

    /**
     * @param array<string, string|int> $row
     */
    #[DataProvider('impossibleRows')]
    public function testTheSchemaRefusesAnImpossibleAsset(array $row): void
    {
        try {
            $this->connection->insert('reference_assets', $row);
            self::fail('The schema accepted an asset it must refuse.');
        } catch (DriverException $exception) {
            self::assertSame(self::CHECK_VIOLATION, $exception->getSQLState());
        } finally {
            $this->connection->delete('reference_assets', ['code' => $row['code']]);
        }
    }

    /**
     * @return iterable<string, array{array<string, string|int>}>
     */
    public static function impossibleRows(): iterable
    {
        yield 'a lowercase code' => [self::row(['code' => 'xau'])];
        yield 'a punctuated code' => [self::row(['code' => 'XA-U'])];
        yield 'an unknown kind' => [self::row(['kind' => 'SECURITY'])];
        yield 'a blank name' => [self::row(['display_name' => '   '])];
        yield 'a storage scale beyond NUMERIC(50,24)' => [self::row(['storage_precision' => 25])];
        yield 'a negative storage scale' => [self::row(['storage_precision' => -1])];
        yield 'a display scale deeper than storage' => [self::row(['storage_precision' => 2, 'display_precision' => 4])];
        yield 'an unknown rounding mode' => [self::row(['rounding_mode' => 'HALF_ODD'])];
    }

    /**
     * @param array<string, string|int> $overrides
     *
     * @return array<string, string|int>
     */
    private static function row(array $overrides): array
    {
        return array_merge([
            'code' => 'XAU',
            'kind' => 'FIAT',
            'display_name' => 'Test asset',
            'storage_precision' => 8,
            'display_precision' => 2,
            'rounding_mode' => 'HALF_UP',
        ], $overrides);
    }

    /**
     * @param list<\App\Module\Reference\Domain\Asset> $assets
     *
     * @return list<string>
     */
    private static function codesOf(array $assets): array
    {
        return array_map(static fn ($asset): string => $asset->code->toString(), $assets);
    }
}
