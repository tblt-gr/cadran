<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Domain\LifeInsurance;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Domain\LifeInsurance\EuroFundRate;
use App\Module\Portfolio\Domain\LifeInsurance\EuroFundRateBasis;
use App\Module\Portfolio\Domain\LifeInsurance\InvalidLifeInsurance;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EuroFundRateTest extends TestCase
{
    #[DataProvider('validRates')]
    public function testItAcceptsServedRatesInsideTheBounds(string $served, ?string $bonus): void
    {
        $rate = self::rate($served, $bonus);

        self::assertSame($served, $rate->servedRate->toString());
        self::assertSame($bonus, $rate->bonusRate?->toString());
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function validRates(): iterable
    {
        yield 'demo rate' => ['2.50', null];
        yield 'negative served rate' => ['-0.25', '0'];
        yield 'lower bound' => ['-100', null];
        yield 'upper bound' => ['100', '100'];
        yield 'four decimals' => ['2.8001', '0.1234'];
    }

    #[DataProvider('invalidRates')]
    public function testItRefusesRatesOutsideTheBounds(string $served, ?string $bonus): void
    {
        try {
            self::rate($served, $bonus);
            self::fail('The rate must be refused.');
        } catch (InvalidLifeInsurance $exception) {
            self::assertSame('invalid_euro_fund_rate', $exception->problemCode);
        }
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function invalidRates(): iterable
    {
        yield 'served above bound' => ['100.0001', null];
        yield 'served below bound' => ['-100.0001', null];
        yield 'served five decimals' => ['2.12345', null];
        yield 'negative bonus' => ['2', '-0.0001'];
        yield 'bonus above bound' => ['2', '100.1'];
        yield 'huge' => ['99999999999999999999999999', null];
    }

    public function testTheBasisLabelsAreStable(): void
    {
        self::assertSame(['NET_OF_MANAGEMENT_FEES', 'GROSS_OF_MANAGEMENT_FEES', 'UNKNOWN'], array_map(static fn (EuroFundRateBasis $basis): string => $basis->value, EuroFundRateBasis::cases()));
    }

    private static function rate(string $served, ?string $bonus): EuroFundRate
    {
        return new EuroFundRate(
            id: '00000000-0000-7000-8000-0000000000f2',
            workspace: WorkspaceScope::fromString(WorkspaceFixture::OWN_WORKSPACE),
            contractId: '00000000-0000-7000-8000-0000000000c1',
            instrumentId: '00000000-0000-7000-8000-000000000b03',
            year: 2025,
            servedRate: DecimalValue::fromString($served),
            bonusRate: null === $bonus ? null : DecimalValue::fromString($bonus),
            basis: EuroFundRateBasis::NET_OF_MANAGEMENT_FEES,
            sourceNote: null,
            recordedAt: new \DateTimeImmutable('2026-03-01'),
            recordedBy: '00000000-0000-7000-8000-0000000000a1',
        );
    }
}
