<?php

declare(strict_types=1);

namespace App\Tests\Module\Foundation\Application;

use App\Module\Foundation\Application\AmountInput;
use App\Module\Foundation\Application\AmountInputParser;
use App\Module\Foundation\Application\InvalidAmountInput;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\RoundingMode;
use App\Module\Reference\Application\AssetCatalog;
use App\Module\Reference\Domain\Asset;
use App\Module\Reference\Domain\AssetKind;
use App\Module\Reference\Domain\AssetPrecision;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AmountInputParserTest extends TestCase
{
    public function testTheTransportInputKeepsMixedMembersAndItsPointerWithoutCoercion(): void
    {
        $input = new AmountInput('230.5688', 'EUR', '/amount');

        self::assertSame('230.5688', $input->value);
        self::assertSame('EUR', $input->assetCode);
        self::assertSame('/amount', $input->pointer);
    }

    #[DataProvider('acceptedAmounts')]
    public function testItReturnsAnExactAmountAtTheAssetStoragePrecision(string $value, string $assetCode): void
    {
        $amount = (new AmountInputParser(self::catalog()))([
            'value' => $value,
            'assetCode' => $assetCode,
        ], '/amount');

        self::assertSame($value, $amount->value->toString());
        self::assertSame($assetCode, $amount->asset->toString());
    }

    /** @return iterable<string, array{string, string}> */
    public static function acceptedAmounts(): iterable
    {
        yield 'euro source precision' => ['230.5688', 'EUR'];
        yield 'ethereum storage precision' => ['0.000000000000000001', 'ETH'];
    }

    #[DataProvider('invalidAmounts')]
    public function testItRejectsEachInvalidMemberWithASafeRuleAndJsonPointer(
        mixed $fragment,
        string $rule,
        string $pointer,
        ?string $secretLiteral = null,
    ): void {
        try {
            (new AmountInputParser(self::catalog()))($fragment, '/amount');
            self::fail('The invalid amount should have been refused.');
        } catch (InvalidAmountInput $failure) {
            self::assertSame($rule, $failure->ruleCode);
            self::assertSame($pointer, $failure->pointer);
            if (null !== $secretLiteral) {
                self::assertStringNotContainsString($secretLiteral, $failure->getMessage());
            }
        }
    }

    /** @return iterable<string, array{mixed, string, string, 3?: string}> */
    public static function invalidAmounts(): iterable
    {
        yield 'absent fragment' => [null, 'amount.missing', '/amount'];
        yield 'list instead of object' => [[], 'amount.missing', '/amount'];
        yield 'missing value' => [['assetCode' => 'EUR'], 'amount.missing', '/amount'];
        yield 'missing asset' => [['value' => '1'], 'amount.missing', '/amount'];
        yield 'numeric value' => [['value' => 1, 'assetCode' => 'EUR'], 'amount.not_a_string', '/amount/value'];
        yield 'numeric asset' => [['value' => '1', 'assetCode' => 978], 'amount.not_a_string', '/amount/assetCode'];
        yield 'non canonical' => [['value' => 'account-secret-1e3', 'assetCode' => 'EUR'], 'amount.not_canonical', '/amount/value', 'account-secret-1e3'];
        yield 'storage scale' => [['value' => '0.0000000000000000000000001', 'assetCode' => 'BTC'], 'amount.precision_exceeded', '/amount/value'];
        yield 'integer width' => [['value' => '999999999999999999999999999', 'assetCode' => 'EUR'], 'amount.precision_exceeded', '/amount/value'];
        yield 'unknown asset' => [['value' => '1', 'assetCode' => 'ZZZ'], 'amount.asset_unknown', '/amount/assetCode'];
        yield 'malformed asset' => [['value' => '1', 'assetCode' => 'eur'], 'amount.asset_unknown', '/amount/assetCode'];
        yield 'asset scale' => [['value' => '1.123456789', 'assetCode' => 'EUR'], 'amount.asset_precision_exceeded', '/amount/value'];
    }

    private static function catalog(): AssetCatalog
    {
        return new class implements AssetCatalog {
            /** @var list<Asset> */
            private array $assets;

            public function __construct()
            {
                $this->assets = [
                    self::asset('BTC', AssetKind::CRYPTO, 8),
                    self::asset('ETH', AssetKind::CRYPTO, 18),
                    self::asset('EUR', AssetKind::FIAT, 8),
                ];
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

            private static function asset(string $code, AssetKind $kind, int $storage): Asset
            {
                return new Asset(
                    AssetCode::fromString($code),
                    $kind,
                    $code,
                    new AssetPrecision($storage, min($storage, 8)),
                    RoundingMode::HALF_UP,
                );
            }
        };
    }
}
