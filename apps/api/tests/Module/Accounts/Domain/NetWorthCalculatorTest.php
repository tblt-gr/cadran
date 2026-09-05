<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Domain;

use App\Module\Accounts\Domain\NetWorth;
use App\Module\Accounts\Domain\NetWorthCalculator;
use App\Module\Accounts\Domain\NetWorthContribution;
use App\Module\Accounts\Domain\NetWorthReason;
use App\Module\Accounts\Domain\ValuationQuality;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use PHPUnit\Framework\TestCase;

/**
 * Hand-checked reference for the dated net-worth aggregate.
 *
 * Three accounts on 3 September 2026, all in EUR:
 *   Livret A         SAVINGS    included  22 950.00   sign +1
 *   Prêt immobilier  LIABILITY  included 225 000.00   sign -1
 *   Compte joint     CURRENT    excluded   1 000.00
 *
 * net worth = (+1 x 22 950.00) + (-1 x 225 000.00) = -202 050.00 EUR
 *
 * The excluded account enters neither side. A liability stores a positive
 * outstanding amount and the sign comes from the kind.
 */
final class NetWorthCalculatorTest extends TestCase
{
    private const string LIVRET = '00000000-0000-7000-8000-0000000000d1';
    private const string LOAN = '00000000-0000-7000-8000-0000000000d2';
    private const string JOINT = '00000000-0000-7000-8000-0000000000d3';

    public function testNetWorthIsTheSumOfSignedIncludedValues(): void
    {
        $netWorth = self::compute($this->reference());

        self::assertSame('-202050.00', $netWorth->total?->toString());
        self::assertSame('EUR', $netWorth->asset?->toString());
        self::assertNull($netWorth->reason);
        self::assertSame(2, $netWorth->eligibleAccountCount);
        self::assertSame(2, $netWorth->valuedAccountCount);
    }

    public function testAnExcludedAccountEntersNeitherSide(): void
    {
        $withoutTheJointAccount = array_values(array_filter(
            $this->reference(),
            static fn (NetWorthContribution $contribution): bool => self::JOINT !== $contribution->accountId,
        ));

        self::assertSame(
            self::compute($withoutTheJointAccount)->total?->toString(),
            self::compute($this->reference())->total?->toString(),
        );
    }

    public function testAMissingValuationLeavesTheTotalNullWithItsReason(): void
    {
        $netWorth = self::compute([
            $this->valued(self::LIVRET, '22950.00'),
            $this->missing(self::LOAN, sign: -1),
        ]);

        self::assertNull($netWorth->total);
        self::assertSame(NetWorthReason::MISSING_VALUATION, $netWorth->reason);
        self::assertSame(ValuationQuality::MISSING, $netWorth->quality);
        self::assertSame(1, $netWorth->missingValuationCount);
        self::assertSame(1, $netWorth->valuedAccountCount);
    }

    public function testNoEligibleAccountIsStatedRatherThanShownAsZero(): void
    {
        $netWorth = self::compute([$this->valued(self::JOINT, '1000.00', eligible: false)]);

        self::assertNull($netWorth->total);
        self::assertSame(NetWorthReason::NO_ELIGIBLE_ACCOUNT, $netWorth->reason);
        self::assertSame(0, $netWorth->eligibleAccountCount);
    }

    public function testTwoAssetsAreRefusedRatherThanAddedTogether(): void
    {
        $netWorth = self::compute([
            $this->valued(self::LIVRET, '22950.00'),
            $this->valued(self::LOAN, '3.00000000', asset: 'BTC'),
        ]);

        self::assertNull($netWorth->total);
        self::assertSame(NetWorthReason::MIXED_ASSETS, $netWorth->reason);
    }

    public function testACarriedForwardValuationMakesTheAggregateStaleAndKeepsTheOldestAge(): void
    {
        $netWorth = self::compute([
            $this->valued(self::LIVRET, '22950.00'),
            $this->valued(self::LOAN, '225000.00', sign: -1, quality: ValuationQuality::STALE, ageDays: 12),
        ]);

        self::assertSame('-202050.00', $netWorth->total?->toString());
        self::assertSame(ValuationQuality::STALE, $netWorth->quality);
        self::assertSame(12, $netWorth->stalestAgeDays);
        self::assertSame(1, $netWorth->staleValuationCount);
    }

    public function testAZeroNetWorthIsAFigureAndNotAMissingValue(): void
    {
        $netWorth = self::compute([
            $this->valued(self::LIVRET, '1000.00'),
            $this->valued(self::LOAN, '1000.00', sign: -1),
        ]);

        self::assertSame('0.00', $netWorth->total?->toString());
        self::assertNull($netWorth->reason);
    }

    /** @return list<NetWorthContribution> */
    private function reference(): array
    {
        return [
            $this->valued(self::LIVRET, '22950.00'),
            $this->valued(self::LOAN, '225000.00', sign: -1),
            $this->valued(self::JOINT, '1000.00', eligible: false),
        ];
    }

    /** @param list<NetWorthContribution> $contributions */
    private static function compute(array $contributions): NetWorth
    {
        return NetWorthCalculator::compute(
            $contributions,
            new \DateTimeImmutable('2026-09-03', new \DateTimeZone('UTC')),
        );
    }

    private function valued(
        string $accountId,
        string $value,
        int $sign = 1,
        bool $eligible = true,
        string $asset = 'EUR',
        ValuationQuality $quality = ValuationQuality::CURRENT,
        ?int $ageDays = 0,
    ): NetWorthContribution {
        return new NetWorthContribution(
            accountId: $accountId,
            netWorthSign: $sign,
            primaryGroupId: null,
            tagGroupIds: [],
            amount: new AssetAmount(DecimalValue::fromString($value), AssetCode::fromString($asset)),
            quality: $quality,
            ageDays: $ageDays,
            valuedOn: new \DateTimeImmutable('2026-09-03', new \DateTimeZone('UTC')),
            eligible: $eligible,
        );
    }

    private function missing(string $accountId, int $sign = 1): NetWorthContribution
    {
        return new NetWorthContribution(
            accountId: $accountId,
            netWorthSign: $sign,
            primaryGroupId: null,
            tagGroupIds: [],
            amount: null,
            quality: ValuationQuality::MISSING,
            ageDays: null,
            valuedOn: null,
            eligible: true,
        );
    }
}
