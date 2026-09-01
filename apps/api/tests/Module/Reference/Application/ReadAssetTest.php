<?php

declare(strict_types=1);

namespace App\Tests\Module\Reference\Application;

use App\Module\Reference\Application\AssetNotFound;
use App\Module\Reference\Application\ReadAsset;
use App\Tests\Module\Reference\Application\Double\InMemoryAssetCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReadAssetTest extends TestCase
{
    public function testAKnownCodeReadsItsAsset(): void
    {
        $catalog = InMemoryAssetCatalog::withCodes('EUR', 'BTC');

        $asset = (new ReadAsset($catalog))('BTC');

        self::assertSame('BTC', $asset->code->toString());
    }

    #[DataProvider('absentCodes')]
    public function testACodeThatNamesNothingAnswersTheSameWayWhateverItsShape(string $code): void
    {
        $catalog = InMemoryAssetCatalog::withCodes('EUR');

        $this->expectException(AssetNotFound::class);

        (new ReadAsset($catalog))($code);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function absentCodes(): iterable
    {
        yield 'an unknown but well-formed code' => ['XAU'];
        // A malformed code is answered like an unknown one: the reference is
        // public to any session, but the two cases share one response so the
        // endpoint has a single, predictable contract.
        yield 'a lowercase code' => ['eur'];
        yield 'an empty code' => [''];
        yield 'an oversized code' => [str_repeat('A', 64)];
    }
}
