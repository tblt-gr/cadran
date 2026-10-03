<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Domain\Valuation;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Portfolio\Domain\Valuation\CashSource;
use App\Module\Portfolio\Domain\Valuation\PositionSnapshot;
use App\Module\Portfolio\Domain\Valuation\PriceQuality;
use App\Module\Portfolio\Domain\Valuation\PriceSource;
use App\Module\Portfolio\Domain\Valuation\Valuation;
use App\Module\Portfolio\Domain\Valuation\ValuationCompleteness;
use App\Module\Portfolio\Domain\Valuation\ValuationDetail;
use App\Module\Portfolio\Domain\Valuation\ValuationFlag;
use App\Module\Portfolio\Domain\Valuation\ValuationRules;
use App\Module\Portfolio\Domain\Valuation\ValuationSource;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ValuationTest extends TestCase
{
    private const string ID = '00000000-0000-7000-8000-000000000c01';

    #[Test]
    public function theToleranceIsHalfADisplayUnitPerPricedPositionPlusOne(): void
    {
        self::assertSame('0.015', ValuationRules::roundingTolerance(2, 2)->toString());
        self::assertSame('0.005', ValuationRules::roundingTolerance(0, 2)->toString());
        self::assertSame('1.5', ValuationRules::roundingTolerance(2, 0)->toString());
    }

    #[Test]
    public function anObservedTotalInsideTheToleranceRaisesNoFlagAndReportsTheExactDifference(): void
    {
        $valuation = $this->valuation(total: '3664.701', observedTotal: '3664.70', snapshots: [$this->snapshot('2314.521', PriceQuality::CURRENT), $this->snapshot('156.90', PriceQuality::CURRENT)]);

        self::assertSame('-0.001', $valuation->observedTotalDifference()?->toString());
        self::assertSame('0.015', $valuation->tolerance(2)->toString());
        self::assertSame([], $valuation->flags(2));
    }

    #[Test]
    public function anObservedTotalBeyondTheToleranceIsFlagged(): void
    {
        $valuation = $this->valuation(total: '3664.701', observedTotal: '3664.80', snapshots: [$this->snapshot('2314.521', PriceQuality::CURRENT), $this->snapshot('156.90', PriceQuality::CURRENT)]);

        self::assertSame([ValuationFlag::OBSERVED_TOTAL_DIFFERENCE], $valuation->flags(2));
        self::assertSame('0.099', $valuation->observedTotalDifference()?->toString());
    }

    #[Test]
    public function aPartialValuationHasNoObservedDifference(): void
    {
        $valuation = $this->valuation(total: null, securities: null, observedTotal: '3664.70', completeness: ValuationCompleteness::PARTIAL, snapshots: [$this->snapshot(null, PriceQuality::MISSING)]);

        self::assertNull($valuation->observedTotalDifference());
        self::assertSame([], $valuation->flags(2));
        self::assertSame(PriceQuality::MISSING, $valuation->priceQuality());
    }

    #[Test]
    public function anObservedCashDifferentFromTheDerivedCashIsFlaggedWithItsDifference(): void
    {
        $valuation = $this->valuation(total: '3664.70', cash: '1200.00', derivedCash: '1193.28', cashSource: CashSource::OBSERVED);

        self::assertSame('6.72', $valuation->cashDifference()?->toString());
        self::assertSame([ValuationFlag::CASH_DIFFERENCE], $valuation->flags(2));
    }

    #[Test]
    public function staleAndNegativeConditionsAreFlagged(): void
    {
        $valuation = $this->valuation(total: '10.00', securities: '-5.00', snapshots: [$this->snapshot('1.00', PriceQuality::STALE)]);

        self::assertSame([ValuationFlag::STALE_PRICES, ValuationFlag::NEGATIVE_SECURITIES_VALUE], $valuation->flags(2));
    }

    #[Test]
    public function aTotalValuationReportsNotDetailedQuality(): void
    {
        $valuation = $this->valuation(total: '3664.70', detail: ValuationDetail::TOTAL, snapshots: [$this->snapshot(null, PriceQuality::NOT_DETAILED)]);

        self::assertSame(PriceQuality::NOT_DETAILED, $valuation->priceQuality());
    }

    #[Test]
    public function aTotalValuationWithoutHoldingsIsStillNotDetailed(): void
    {
        self::assertSame(PriceQuality::NOT_DETAILED, $this->valuation(detail: ValuationDetail::TOTAL, snapshots: [])->priceQuality());
        self::assertSame(PriceQuality::CURRENT, $this->valuation(detail: ValuationDetail::POSITIONS, snapshots: [])->priceQuality());
    }

    #[Test]
    public function withdrawingKeepsTheFiguresAndMarksTheRowInactive(): void
    {
        $valuation = $this->valuation(total: '3664.70');
        $at = new \DateTimeImmutable('2026-04-02 10:00:00+00');

        $withdrawn = $valuation->supersededAt($at, true);

        self::assertFalse($withdrawn->isActive());
        self::assertTrue($withdrawn->withdrawn);
        self::assertSame(2, $withdrawn->version);
        self::assertSame('3664.70', $withdrawn->totalValue?->toString());
        self::assertTrue($valuation->isActive());
    }

    /** @param list<PositionSnapshot> $snapshots */
    private function valuation(
        ?string $total = '3664.70',
        ?string $securities = '2471.42',
        string $cash = '1193.28',
        string $derivedCash = '1193.28',
        CashSource $cashSource = CashSource::DERIVED,
        ?string $observedTotal = null,
        ValuationDetail $detail = ValuationDetail::POSITIONS,
        ValuationCompleteness $completeness = ValuationCompleteness::COMPLETE,
        array $snapshots = [],
    ): Valuation {
        $dec = static fn (?string $literal): ?DecimalValue => null === $literal ? null : DecimalValue::fromString($literal);

        return new Valuation(
            id: self::ID, workspace: WorkspaceFixture::own(), accountId: '00000000-0000-7000-8000-0000000000d1',
            valuedOn: new \DateTimeImmutable('2026-03-31', new \DateTimeZone('UTC')), assetCode: AssetCode::fromString('EUR'),
            cash: $dec($cash) ?? DecimalValue::zero(), derivedCash: $dec($derivedCash) ?? DecimalValue::zero(), cashSource: $cashSource,
            securitiesValue: $dec($securities), totalValue: $dec($total), observedTotal: $dec($observedTotal),
            detail: $detail, completeness: $completeness, source: ValuationSource::MANUAL, note: null, supersedesId: null,
            supersededAt: null, withdrawn: false, recordedAt: new \DateTimeImmutable('2026-04-01 09:00:00+00'),
            recordedBy: '00000000-0000-7000-8000-0000000000a1', version: 1, positions: $snapshots,
        );
    }

    private function snapshot(?string $market, PriceQuality $quality): PositionSnapshot
    {
        return new PositionSnapshot(
            '00000000-0000-7000-8000-000000000d01', self::ID, '00000000-0000-7000-8000-000000000b01', DecimalValue::fromString('1'),
            null === $market ? null : DecimalValue::fromString($market), null === $market ? null : new \DateTimeImmutable('2026-03-30', new \DateTimeZone('UTC')),
            null === $market ? null : PriceSource::MANUAL, null === $market ? null : DecimalValue::fromString($market), null, $quality,
        );
    }
}
