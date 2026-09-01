<?php

declare(strict_types=1);

namespace App\Tests\Module\Reference\Domain;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\PrecisionExceeded;
use App\Module\Foundation\Domain\RoundingMode;
use App\Module\Reference\Domain\Asset;
use App\Module\Reference\Domain\AssetKind;
use App\Module\Reference\Domain\AssetPrecision;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An asset is the reference every financial decimal is read against: how many
 * decimals may be stored, how many are shown, and which rounding rule the
 * presentation boundary must apply.
 */
final class AssetTest extends TestCase
{
    public function testAnAssetAcceptsAValueAtItsStorageScale(): void
    {
        $euro = self::euro();

        $amount = $euro->amount('230.5688');

        self::assertSame('230.5688', $amount->value->toString());
        self::assertSame('EUR', $amount->asset->toString());
    }

    public function testAnAssetRefusesAValueDeeperThanItsStorageScale(): void
    {
        $euro = self::euro();

        $this->expectException(PrecisionExceeded::class);

        $euro->amount('1.123456789');
    }

    #[DataProvider('displaySteps')]
    public function testTheDisplayStepIsTheSmallestShownAmount(int $displayPrecision, string $expected): void
    {
        $asset = new Asset(
            code: AssetCode::fromString('EUR'),
            kind: AssetKind::FIAT,
            displayName: 'Euro',
            precision: new AssetPrecision(storage: 8, display: $displayPrecision),
            roundingMode: RoundingMode::HALF_UP,
        );

        self::assertSame($expected, $asset->displayStep()->value->toString());
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function displaySteps(): iterable
    {
        yield 'a currency without minor units' => [0, '1'];
        yield 'a currency with cents' => [2, '0.01'];
        yield 'a satoshi-grained asset' => [8, '0.00000001'];
    }

    #[DataProvider('invalidPrecisions')]
    public function testAPrecisionOutsideTheStorageTypeIsRefused(int $storage, int $display): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AssetPrecision(storage: $storage, display: $display);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function invalidPrecisions(): iterable
    {
        yield 'a storage scale beyond NUMERIC(50,24)' => [25, 2];
        yield 'a negative storage scale' => [-1, 0];
        yield 'a negative display scale' => [8, -1];
        // Showing more decimals than are stored would invent digits.
        yield 'a display scale deeper than storage' => [2, 4];
    }

    #[DataProvider('invalidCodes')]
    public function testAnAssetCodeIsRefusedUnlessItIsCanonical(string $code): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AssetCode::fromString($code);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCodes(): iterable
    {
        yield 'empty' => [''];
        yield 'lowercase' => ['eur'];
        yield 'mixed case' => ['Eur'];
        yield 'one character' => ['E'];
        yield 'spaced' => ['EU R'];
        yield 'punctuated' => ['EU-R'];
        yield 'leading digit' => ['1BTC'];
        yield 'too long' => ['ABCDEFGHIJKLM'];
        yield 'trailing newline' => ["EUR\n"];
    }

    public function testAnAssetNameIsBounded(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Asset(
            code: AssetCode::fromString('EUR'),
            kind: AssetKind::FIAT,
            displayName: str_repeat('a', 65),
            precision: new AssetPrecision(storage: 8, display: 2),
            roundingMode: RoundingMode::HALF_UP,
        );
    }

    private static function euro(): Asset
    {
        return new Asset(
            code: AssetCode::fromString('EUR'),
            kind: AssetKind::FIAT,
            displayName: 'Euro',
            precision: new AssetPrecision(storage: 8, display: 2),
            roundingMode: RoundingMode::HALF_UP,
        );
    }
}
