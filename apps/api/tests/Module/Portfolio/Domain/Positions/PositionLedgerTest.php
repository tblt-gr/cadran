<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Domain\Positions;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Domain\OperationDetails;
use App\Module\Portfolio\Domain\OperationState;
use App\Module\Portfolio\Domain\OperationType;
use App\Module\Portfolio\Domain\PortfolioOperation;
use App\Module\Portfolio\Domain\Positions\PositionLedger;
use App\Module\Portfolio\Domain\Positions\PositionReplayFailed;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PositionLedgerTest extends TestCase
{
    private const string ACCOUNT = '00000000-0000-7000-8000-0000000000d1';
    private const string ETF = '00000000-0000-7000-8000-000000000b01';
    private const string STOCK = '00000000-0000-7000-8000-000000000b02';

    #[Test]
    public function itReconcilesFifoLotsAndTheDistinctAverageCostPoolExactly(): void
    {
        $result = (new PositionLedger())->replay([
            $this->operation('01', OperationType::BUY, '2026-01-02', 1, self::ETF, '10', gross: '1000.00', fees: '2.00'),
            $this->operation('02', OperationType::BUY, '2026-01-03', 1, self::ETF, '5', gross: '600.00', fees: '1.00'),
            $this->operation('03', OperationType::SELL, '2026-01-04', 1, self::ETF, '12', gross: '1400.00'),
        ], [self::ETF => 6]);

        $position = $result->positions[self::ETF];
        self::assertSame('3', $position->quantity->toString());
        self::assertSame('360.600000000000000000000000', $position->fifoCostBasis?->toString());
        self::assertSame('320.600000000000000000000000', $position->averageCostBasis?->toString());
        self::assertSame('106.866666666666666666666667', $position->averageUnitCost?->toString());
        self::assertSame('00000000-0000-7000-8000-000000000002#0', $position->openLots[0]->lotId);
        self::assertSame('3', $position->openLots[0]->openQuantity->toString());
    }

    #[Test]
    public function itKeepsPartialReliefAndRemainingCostEqualToTheOriginal(): void
    {
        $result = (new PositionLedger())->replay([
            $this->operation('01', OperationType::BUY, '2026-01-02', 1, self::ETF, '3', gross: '100.00'),
            $this->operation('02', OperationType::SELL, '2026-01-03', 1, self::ETF, '1', gross: '40.00'),
        ], [self::ETF => 6]);

        $lot = $result->positions[self::ETF]->openLots[0];
        self::assertSame('66.666666666666666666666667', $lot->costBasis?->toString());
        $movements = $result->movements[self::ETF];
        self::assertSame('-33.333333333333333333333333', $movements[1]->costDelta?->toString());
    }

    #[Test]
    public function itAggregatesValidStoredDecimalsBeyondTheStorageIntegerWidth(): void
    {
        $maximum = '99999999999999999999999999.00';
        $result = (new PositionLedger())->replay([
            $this->operation('01', OperationType::BUY, '2026-01-01', 1, self::ETF, '1', gross: $maximum, fees: $maximum, taxes: $maximum),
            $this->operation('02', OperationType::BUY, '2026-01-02', 1, self::ETF, '1', gross: $maximum, fees: $maximum, taxes: $maximum),
        ], [self::ETF => 6]);

        $position = $result->positions[self::ETF];
        self::assertSame('2', $position->quantity->toString());
        self::assertSame('599999999999999999999999994.00', $position->fifoCostBasis?->toString());
        self::assertSame('599999999999999999999999994.00', $position->averageCostBasis?->toString());
        self::assertSame('299999999999999999999999997.000000000000000000000000', $position->averageUnitCost?->toString());
    }

    #[Test]
    public function itRejectsTheFirstNegativeHoldingWithIdentifiersOnly(): void
    {
        $sell = $this->operation('02', OperationType::SELL, '2026-01-03', 1, self::ETF, '1', gross: '40.00');

        try {
            (new PositionLedger())->replay([$sell], [self::ETF => 6]);
            self::fail('Expected the replay to fail.');
        } catch (PositionReplayFailed $failure) {
            self::assertSame('position_would_go_negative', $failure->problemCode);
            self::assertSame([
                'operationId' => $sell->id,
                'tradeDate' => '2026-01-03',
                'instrumentId' => self::ETF,
            ], $failure->extensions);
        }
    }

    #[Test]
    public function itTracksUnknownTransferCostUntilThePositionResets(): void
    {
        $ledger = new PositionLedger();
        $partial = $ledger->replay([
            $this->operation('01', OperationType::SECURITY_TRANSFER_IN, '2026-01-02', 1, self::ETF, '4'),
            $this->operation('02', OperationType::SELL, '2026-01-03', 1, self::ETF, '1', gross: '40.00'),
        ], [self::ETF => 6]);
        self::assertNull($partial->positions[self::ETF]->fifoCostBasis);
        self::assertSame('UNKNOWN_LOT_COST', $partial->positions[self::ETF]->costReason);

        $reset = $ledger->replay([
            $this->operation('01', OperationType::SECURITY_TRANSFER_IN, '2026-01-02', 1, self::ETF, '4'),
            $this->operation('02', OperationType::SELL, '2026-01-03', 1, self::ETF, '4', gross: '40.00'),
            $this->operation('03', OperationType::BUY, '2026-01-04', 1, self::ETF, '2', gross: '20.00'),
        ], [self::ETF => 6]);
        self::assertSame('20.00', $reset->positions[self::ETF]->fifoCostBasis?->toString());
        self::assertNull($reset->positions[self::ETF]->costReason);

        $unknownAverageOnly = $ledger->replay([
            $this->operation('01', OperationType::SECURITY_TRANSFER_IN, '2026-01-01', 1, self::ETF, '5'),
            $this->operation('02', OperationType::BUY, '2026-01-02', 1, self::ETF, '10', gross: '100.00'),
            $this->operation('03', OperationType::SELL, '2026-01-03', 1, self::ETF, '5', gross: '50.00'),
        ], [self::ETF => 6]);
        self::assertSame('100.00', $unknownAverageOnly->positions[self::ETF]->fifoCostBasis?->toString());
        self::assertNull($unknownAverageOnly->positions[self::ETF]->averageCostBasis);
        self::assertSame('UNKNOWN_LOT_COST', $unknownAverageOnly->positions[self::ETF]->costReason);
    }

    #[Test]
    public function itKeepsAveragePoolsIndependentFromFifoThroughCorporateActions(): void
    {
        $base = [
            $this->operation('01', OperationType::BUY, '2026-01-01', 1, self::ETF, '10', gross: '1000.00', fees: '2.00'),
            $this->operation('02', OperationType::BUY, '2026-01-02', 1, self::ETF, '5', gross: '600.00', fees: '1.00'),
            $this->operation('03', OperationType::SELL, '2026-01-03', 1, self::ETF, '12', gross: '1200.00'),
        ];
        $merged = (new PositionLedger())->replay([
            ...$base,
            $this->operation('04', OperationType::MERGER, '2026-01-04', 1, self::ETF, counter: self::STOCK, counterQuantity: '3'),
        ], [self::ETF => 6, self::STOCK => 6]);
        self::assertSame('360.600000000000000000000000', $merged->positions[self::STOCK]->fifoCostBasis?->toString());
        self::assertSame('320.600000000000000000000000', $merged->positions[self::STOCK]->averageCostBasis?->toString());

        $spunOff = (new PositionLedger())->replay([
            ...$base,
            $this->operation('04', OperationType::SPIN_OFF, '2026-01-04', 1, self::ETF, counter: self::STOCK, counterQuantity: '3', allocation: '0.1'),
        ], [self::ETF => 6, self::STOCK => 6]);
        self::assertSame('324.540000000000000000000000', $spunOff->positions[self::ETF]->fifoCostBasis?->toString());
        self::assertSame('288.540000000000000000000000', $spunOff->positions[self::ETF]->averageCostBasis?->toString());
        self::assertSame('36.060000000000000000000000', $spunOff->positions[self::STOCK]->fifoCostBasis?->toString());
        self::assertSame('32.060000000000000000000000', $spunOff->positions[self::STOCK]->averageCostBasis?->toString());
    }

    #[Test]
    public function itRestoresFifoOrderAndDoesNotKeepZeroQuantityCorporateLots(): void
    {
        $ordered = (new PositionLedger())->replay([
            $this->operation('01', OperationType::BUY, '2026-01-01', 1, self::ETF, '1', gross: '10.00'),
            $this->operation('02', OperationType::BUY, '2026-01-02', 1, self::STOCK, '1', gross: '5.00'),
            $this->operation('03', OperationType::BUY, '2026-01-03', 1, self::ETF, '1', gross: '20.00'),
            $this->operation('04', OperationType::MERGER, '2026-01-04', 1, self::ETF, counter: self::STOCK, counterQuantity: '4'),
        ], [self::ETF => 0, self::STOCK => 0]);
        self::assertSame(
            ['2026-01-01', '2026-01-02', '2026-01-03'],
            array_map(static fn ($lot): string => $lot->acquiredOn->format('Y-m-d'), $ordered->positions[self::STOCK]->openLots),
        );

        $withoutZeroLots = (new PositionLedger())->replay([
            $this->operation('01', OperationType::BUY, '2026-01-01', 1, self::ETF, '1', gross: '10.00'),
            $this->operation('02', OperationType::BUY, '2026-01-02', 1, self::ETF, '1', gross: '20.00'),
            $this->operation('03', OperationType::BUY, '2026-01-03', 1, self::ETF, '1', gross: '30.00'),
            $this->operation('04', OperationType::MERGER, '2026-01-04', 1, self::ETF, counter: self::STOCK, counterQuantity: '1'),
        ], [self::ETF => 0, self::STOCK => 0]);
        self::assertCount(1, $withoutZeroLots->positions[self::STOCK]->openLots);
        self::assertSame('1', $withoutZeroLots->positions[self::STOCK]->openLots[0]->openQuantity->toString());
        self::assertSame('60.00', $withoutZeroLots->positions[self::STOCK]->openLots[0]->costBasis?->toString());
    }

    #[Test]
    public function itAppliesSplitMergerAndSpinOffRulesToEachLot(): void
    {
        $result = (new PositionLedger())->replay([
            $this->operation('01', OperationType::BUY, '2026-01-01', 1, self::ETF, '1', gross: '10.00'),
            $this->operation('02', OperationType::BUY, '2026-01-01', 2, self::ETF, '1', gross: '20.00'),
            $this->operation('03', OperationType::BUY, '2026-01-01', 3, self::ETF, '1', gross: '30.00'),
            $this->operation('04', OperationType::SPLIT, '2026-01-02', 1, self::ETF, ratio: ['2', '1']),
            $this->operation('05', OperationType::SPIN_OFF, '2026-01-03', 1, self::ETF, counter: self::STOCK, counterQuantity: '3', allocation: '0.1'),
            $this->operation('06', OperationType::MERGER, '2026-01-04', 1, self::ETF, counter: self::STOCK, counterQuantity: '7'),
        ], [self::ETF => 0, self::STOCK => 0]);

        self::assertSame('0', $result->positions[self::ETF]->quantity->toString());
        self::assertSame(['1', '2', '1', '2', '1', '3'], array_map(
            static fn ($lot): string => $lot->openQuantity->toString(),
            $result->positions[self::STOCK]->openLots,
        ));
        self::assertSame('60.000000000000000000000000', $result->positions[self::STOCK]->fifoCostBasis?->toString());
    }

    #[Test]
    public function itRejectsASplitFractionOutsideTheInstrumentPrecision(): void
    {
        $split = $this->operation('02', OperationType::SPLIT, '2026-01-02', 1, self::ETF, ratio: ['1', '3']);
        $this->expectException(PositionReplayFailed::class);
        $this->expectExceptionMessage('split_fraction_unsupported');

        (new PositionLedger())->replay([
            $this->operation('01', OperationType::BUY, '2026-01-01', 1, self::ETF, '2', gross: '20.00'),
            $split,
        ], [self::ETF => 0]);
    }

    #[Test]
    public function itAcceptsASplitFractionRepresentableAtPrecisionSix(): void
    {
        $result = (new PositionLedger())->replay([
            $this->operation('01', OperationType::BUY, '2026-01-01', 1, self::ETF, '3', gross: '30.00'),
            $this->operation('02', OperationType::SPLIT, '2026-01-02', 1, self::ETF, ratio: ['1', '2']),
        ], [self::ETF => 6]);

        self::assertSame('1.500000', $result->positions[self::ETF]->quantity->toString());
        self::assertSame('1.500000', $result->positions[self::ETF]->openLots[0]->openQuantity->toString());
        self::assertSame('30.00', $result->positions[self::ETF]->fifoCostBasis?->toString());
    }

    #[Test]
    public function itFlagsDaysWhoseResultDependsOnOperationOrder(): void
    {
        $result = (new PositionLedger())->replay([
            $this->operation('01', OperationType::BUY, '2026-01-01', 1, self::ETF, '2', gross: '20.00'),
            $this->operation('02', OperationType::SELL, '2026-01-01', 2, self::ETF, '1', gross: '10.00'),
        ], [self::ETF => 6]);

        self::assertSame(['2026-01-01'], $result->positions[self::ETF]->orderDependentDates);
    }

    #[Test]
    public function itReadsTheHeldQuantitiesAtSeveralDaysFromOneReplay(): void
    {
        $at = (new PositionLedger())->quantitiesAt([
            $this->operation('03', OperationType::SELL, '2026-03-10', 1, self::ETF, '4', gross: '400.00'),
            $this->operation('01', OperationType::BUY, '2026-01-02', 1, self::ETF, '10', gross: '1000.00'),
            $this->operation('02', OperationType::BUY, '2026-02-02', 1, self::STOCK, '0.5', gross: '50.00'),
        ], [self::ETF => 6, self::STOCK => 6], [
            new \DateTimeImmutable('2026-03-10'),
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-02-02'),
        ]);

        self::assertSame([], $at['2026-01-01'] ?? null, 'before any operation nothing is held');
        self::assertSame([self::ETF => '10', self::STOCK => '0.5'], self::strings($at['2026-02-02']), 'the day itself is included');
        self::assertSame([self::ETF => '6', self::STOCK => '0.5'], self::strings($at['2026-03-10']));
    }

    #[Test]
    public function itMarksOnlyTheDaysFromTheFirstFailingOperationOnAsUnreadable(): void
    {
        $at = (new PositionLedger())->quantitiesAt([
            $this->operation('01', OperationType::BUY, '2026-01-02', 1, self::ETF, '1', gross: '100.00'),
            $this->operation('02', OperationType::SELL, '2026-02-03', 1, self::ETF, '2', gross: '40.00'),
        ], [self::ETF => 6], [new \DateTimeImmutable('2026-01-31'), new \DateTimeImmutable('2026-02-03'), new \DateTimeImmutable('2026-03-31')]);

        self::assertSame([self::ETF => '1'], self::strings($at['2026-01-31']));
        self::assertNull($at['2026-02-03']);
        self::assertNull($at['2026-03-31']);
    }

    #[Test]
    public function itAgreesWithAPrefixReplayAtEveryDay(): void
    {
        $operations = [
            $this->operation('01', OperationType::BUY, '2026-01-02', 1, self::ETF, '10', gross: '1000.00'),
            $this->operation('02', OperationType::BUY, '2026-01-02', 2, self::ETF, '2.5', gross: '100.00'),
            $this->operation('03', OperationType::SELL, '2026-01-20', 1, self::ETF, '12', gross: '1400.00'),
        ];
        $ledger = new PositionLedger();
        foreach (['2026-01-02', '2026-01-10', '2026-01-20', '2026-02-01'] as $day) {
            $prefix = array_values(array_filter($operations, static fn (PortfolioOperation $operation): bool => $operation->details->tradeDate->format('Y-m-d') <= $day));
            $expected = [];
            foreach ($ledger->replay($prefix, [self::ETF => 6])->positions as $id => $position) {
                if (!$position->quantity->isZero()) {
                    $expected[$id] = $position->quantity->toString();
                }
            }
            self::assertSame($expected, self::strings($ledger->quantitiesAt($operations, [self::ETF => 6], [new \DateTimeImmutable($day)])[$day]), $day);
        }
    }

    /**
     * @param array<string, \App\Module\Foundation\Domain\CalculatedDecimal>|null $quantities
     *
     * @return array<string, string>
     */
    private static function strings(?array $quantities): array
    {
        self::assertNotNull($quantities);

        return array_map(static fn ($quantity): string => $quantity->toString(), $quantities);
    }

    /** @param array{string, string}|null $ratio */
    private function operation(
        string $suffix,
        OperationType $type,
        string $date,
        int $sequence,
        string $instrument,
        ?string $quantity = null,
        ?string $gross = null,
        ?string $fees = null,
        ?string $taxes = null,
        ?string $counter = null,
        ?string $counterQuantity = null,
        ?array $ratio = null,
        ?string $allocation = null,
    ): PortfolioOperation {
        $details = new OperationDetails(
            $type, new \DateTimeImmutable($date), null, $instrument, $counter,
            null === $quantity ? null : DecimalValue::fromString($quantity),
            in_array($type, [OperationType::BUY, OperationType::SELL], true) ? DecimalValue::fromString('1') : null,
            null === $gross ? null : DecimalValue::fromString($gross),
            null === $fees ? (in_array($type, [OperationType::BUY, OperationType::SELL], true) ? DecimalValue::zero() : null) : DecimalValue::fromString($fees),
            null === $taxes ? (in_array($type, [OperationType::BUY, OperationType::SELL], true) ? DecimalValue::zero() : null) : DecimalValue::fromString($taxes),
            null, null === $counterQuantity ? null : DecimalValue::fromString($counterQuantity),
            null === $ratio ? null : DecimalValue::fromString($ratio[0]),
            null === $ratio ? null : DecimalValue::fromString($ratio[1]),
            null === $allocation ? null : DecimalValue::fromString($allocation),
            null, null, null,
        );

        return new PortfolioOperation(
            '00000000-0000-7000-8000-0000000000'.$suffix,
            WorkspaceScope::fromString('00000000-0000-7000-8000-000000000001'), self::ACCOUNT, AssetCode::fromString('EUR'),
            $details, $sequence, DecimalValue::zero(), null, OperationState::ACTIVE, null, null, null, null,
            null, new \DateTimeImmutable('2026-01-01'), '00000000-0000-7000-8000-000000000002', 1,
        );
    }
}
