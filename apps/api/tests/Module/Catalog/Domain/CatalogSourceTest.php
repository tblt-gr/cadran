<?php

declare(strict_types=1);

namespace App\Tests\Module\Catalog\Domain;

use App\Module\Catalog\Domain\CatalogSource;
use App\Module\Catalog\Domain\InvalidCatalogEntry;
use PHPUnit\Framework\TestCase;

final class CatalogSourceTest extends TestCase
{
    public function testAnOfficialSourceIsReferencedOverTls(): void
    {
        $this->expectException(InvalidCatalogEntry::class);

        self::source('http://www.service-public.fr/particuliers/vosdroits/F2365');
    }

    public function testASourceCannotBeReadBeforeItWasPublished(): void
    {
        $this->expectException(InvalidCatalogEntry::class);

        new CatalogSource(
            publisher: 'Service-Public.fr',
            title: 'Livret A',
            url: 'https://www.service-public.fr/particuliers/vosdroits/F2365',
            publishedOn: CatalogFixture::day('2026-08-22'),
            retrievedOn: CatalogFixture::day('2025-04-25'),
        );
    }

    public function testAnUndatedPublicationStillRecordsWhenItWasRead(): void
    {
        $source = new CatalogSource(
            publisher: 'Service-Public.fr',
            title: 'Livret A',
            url: 'https://www.service-public.fr/particuliers/vosdroits/F2365',
            publishedOn: null,
            retrievedOn: CatalogFixture::day('2026-08-22'),
        );

        self::assertNull($source->publishedOn);
        self::assertSame('2026-08-22', $source->retrievedOn->format('Y-m-d'));
    }

    public function testAnUnnamedPublisherIsRefused(): void
    {
        $this->expectException(InvalidCatalogEntry::class);

        self::source('https://www.service-public.fr/particuliers/vosdroits/F2365', '');
    }

    private static function source(string $url, string $publisher = 'Service-Public.fr'): CatalogSource
    {
        return new CatalogSource(
            publisher: $publisher,
            title: 'Livret A',
            url: $url,
            publishedOn: CatalogFixture::day('2025-04-25'),
            retrievedOn: CatalogFixture::day('2026-08-22'),
        );
    }
}
