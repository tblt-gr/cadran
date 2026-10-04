<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Domain\Performance;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Domain\OperationDetails;
use App\Module\Portfolio\Domain\OperationState;
use App\Module\Portfolio\Domain\OperationType;
use App\Module\Portfolio\Domain\Performance\DecompositionStatus;
use App\Module\Portfolio\Domain\Performance\PerformanceReason;
use App\Module\Portfolio\Domain\Performance\PeriodAnchor;
use App\Module\Portfolio\Domain\Performance\TotalGain;
use App\Module\Portfolio\Domain\Performance\TotalGainCalculator;
use App\Module\Portfolio\Domain\PortfolioOperation;
use App\Module\Portfolio\Domain\Positions\PositionLedger;
use App\Module\Portfolio\Domain\Valuation\PositionSnapshot;
use App\Module\Portfolio\Domain\Valuation\PriceQuality;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TotalGainCalculatorTest extends TestCase
{
    private const string ACCOUNT = '00000000-0000-7000-8000-0000000000d1';
    private const string ETF = '00000000-0000-7000-8000-000000000b01';
    private const string STOCK = '00000000-0000-7000-8000-000000000b02';

    #[Test]
    public function itComputesTheReferenceMonthAndItsExactDecomposition(): void
    {
        $result = $this->referenceMonth();

        self::assertSame('250.00', $result->gain);
        self::assertSame('800.00', $result->netFlows);
        self::assertSame([], $result->blocking);
        self::assertSame([], $result->warnings);
        $decomposition = $result->decomposition;
        self::assertSame(DecompositionStatus::COMPLETE, $decomposition->status);
        self::assertSame('70.00', $decomposition->realized);
        self::assertSame('167.00', $decomposition->unrealizedChange);
        self::assertSame('30.00', $decomposition->income);
        self::assertSame('-8.00', $decomposition->fees);
        self::assertSame('-9.00', $decomposition->taxes);
        self::assertSame('0.00', $decomposition->corporateActionCash);
        self::assertSame('2.00', $decomposition->capitalizedCosts);
        self::assertSame('0.00', $decomposition->residual);
    }

    #[Test]
    public function itComputesAMonthWithAWithdrawalOnly(): void
    {
        $result = $this->calculate(
            $this->anchor('2026-02-28', '1000.00'),
            $this->anchor('2026-03-31', '810.00'),
            [$this->operation('10', OperationType::WITHDRAWAL, '2026-03-10', gross: '200.00')],
        );

        self::assertSame('10.00', $result->gain);
        self::assertSame('-200.00', $result->netFlows);
    }

    #[Test]
    public function itKeepsANegativeGainSigned(): void
    {
        $result = $this->calculate($this->anchor('2026-02-28', '1000.00'), $this->anchor('2026-03-31', '940.50'), []);

        self::assertSame('-59.50', $result->gain);
        self::assertSame('0.00', $result->netFlows);
    }

    #[Test]
    public function itReturnsAZeroGainAsAKnownZeroNotAMissingValue(): void
    {
        $result = $this->calculate(
            $this->anchor('2026-02-28', '1000.00'),
            $this->anchor('2026-03-31', '1500.00'),
            [$this->operation('10', OperationType::DEPOSIT, '2026-03-10', gross: '500.00')],
        );

        self::assertNotNull($result->gain);
        self::assertSame('0.00', $result->gain);
    }

    #[Test]
    public function itAcceptsAnOpeningStartOfValueZero(): void
    {
        $result = $this->calculate(
            PeriodAnchor::opening(new \DateTimeImmutable('2026-02-28')),
            $this->anchor('2026-03-31', '1010.00'),
            [$this->operation('10', OperationType::DEPOSIT, '2026-03-10', gross: '1000.00')],
        );

        self::assertSame('10.00', $result->gain);
        self::assertSame('0.00', $result->startValue);
    }

    #[Test]
    public function itHandlesVeryLargeDecimalsWithoutRounding(): void
    {
        $large = '99999999999999999999999999.000000000000000000000001';
        $result = $this->calculate(
            $this->anchor('2026-02-28', '0.00'),
            $this->anchor('2026-03-31', $large),
            [],
        );

        self::assertSame($large, $result->gain);
    }

    #[Test]
    public function aSecurityTransferInsideThePeriodMakesTheGainNotCalculable(): void
    {
        $result = $this->calculate(
            $this->anchor('2026-02-28', '1000.00'),
            $this->anchor('2026-03-31', '1100.00'),
            [
                $this->operation('10', OperationType::SECURITY_TRANSFER_IN, '2026-03-05', instrument: self::ETF, quantity: '2'),
                $this->operation('11', OperationType::SECURITY_TRANSFER_OUT, '2026-03-06', instrument: self::ETF, quantity: '1'),
            ],
        );

        self::assertNull($result->gain);
        self::assertNull($result->netFlows);
        self::assertSame([PerformanceReason::UNVALUED_SECURITY_TRANSFER], array_map(static fn ($finding) => $finding->code, $result->blocking));
        self::assertSame(['2026-03-05', '2026-03-06'], $result->blocking[0]->dates);
        self::assertSame(DecompositionStatus::UNAVAILABLE, $result->decomposition->status);
        self::assertNull($result->decomposition->realized);
    }

    #[Test]
    public function anUnknownCostAtAnAnchorKeepsTheGainButMakesTheDecompositionUnavailable(): void
    {
        $start = $this->anchor('2026-02-28', '10000.00', securities: '9200.00', positions: [$this->snapshot('92', null)]);
        $end = $this->anchor('2026-03-31', '10100.00', securities: '9300.00', positions: [$this->snapshot('92', '8998.00')]);

        $result = $this->calculate($start, $end, []);

        self::assertSame('100.00', $result->gain);
        self::assertSame(DecompositionStatus::UNAVAILABLE, $result->decomposition->status);
        self::assertSame(PerformanceReason::UNKNOWN_LOT_COST, $result->decomposition->reason);
        self::assertNull($result->decomposition->unrealizedChange);
        self::assertNull($result->decomposition->residual);
    }

    #[Test]
    public function anUnknownReleasedCostMakesTheDecompositionUnavailable(): void
    {
        $ledger = (new PositionLedger())->replay([
            $this->operation('01', OperationType::SECURITY_TRANSFER_IN, '2026-02-01', instrument: self::ETF, quantity: '10'),
            $sell = $this->operation('02', OperationType::SELL, '2026-03-10', instrument: self::ETF, quantity: '2', gross: '210.00'),
        ], [self::ETF => 6]);

        $result = (new TotalGainCalculator())->calculate(
            PeriodAnchor::opening(new \DateTimeImmutable('2026-02-28')),
            $this->anchor('2026-03-31', '210.00'),
            [$sell],
            $ledger,
            2,
        );

        self::assertSame(DecompositionStatus::UNAVAILABLE, $result->decomposition->status);
        self::assertSame(PerformanceReason::UNKNOWN_LOT_COST, $result->decomposition->reason);
    }

    #[Test]
    public function anObservedCashDifferenceAppearsAsAnExactResidualWithAWarning(): void
    {
        $start = $this->anchor('2026-02-28', '1000.00');
        $end = $this->anchor('2026-03-31', '1100.07');

        $result = $this->calculate($start, $end, [$this->operation('10', OperationType::INTEREST, '2026-03-10', gross: '100.00')]);

        self::assertSame('100.07', $result->gain);
        self::assertSame('100.00', $result->decomposition->income);
        self::assertSame('0.07', $result->decomposition->residual);
        self::assertSame([PerformanceReason::DECOMPOSITION_RESIDUAL], array_map(static fn ($finding) => $finding->code, $result->warnings));
    }

    #[Test]
    public function aResidualBelowHalfADisplayStepRaisesNoWarning(): void
    {
        $result = $this->calculate($this->anchor('2026-02-28', '1000.00'), $this->anchor('2026-03-31', '1000.004'), []);

        self::assertSame('0.004', $result->decomposition->residual);
        self::assertSame([], $result->warnings);
    }

    #[Test]
    public function aMergersCashGoesToCorporateActionCash(): void
    {
        $merger = $this->operation('10', OperationType::MERGER, '2026-03-10', instrument: self::ETF, gross: '12.50', counter: self::STOCK, counterQuantity: '3');
        $result = $this->calculate(
            $this->anchor('2026-02-28', '1000.00'),
            $this->anchor('2026-03-31', '1012.50'),
            [$merger],
            [$this->operation('01', OperationType::SECURITY_TRANSFER_IN, '2026-02-01', instrument: self::ETF, quantity: '5'), $merger],
        );

        self::assertSame('12.50', $result->decomposition->corporateActionCash);
        self::assertSame('0.00', $result->decomposition->residual);
    }

    #[Test]
    public function anchorFlagsBecomeWarnings(): void
    {
        $end = $this->anchor('2026-03-31', '1000.00', flags: ['STALE_PRICES']);

        $result = $this->calculate($this->anchor('2026-02-28', '1000.00'), $end, []);

        self::assertSame([PerformanceReason::STALE_PRICES], array_map(static fn ($finding) => $finding->code, $result->warnings));
        self::assertSame(['2026-03-31'], $result->warnings[0]->dates);
    }

    private function referenceMonth(): TotalGain
    {
        $start = $this->anchor('2026-02-28', '10000.00', securities: '9200.00', positions: [$this->snapshot('92', '8998.00')]);
        $end = $this->anchor('2026-03-31', '11050.00', securities: '9189.00', positions: [$this->snapshot('90', '8820.00')]);

        return $this->calculate($start, $end, $this->referenceOperations()['period'], $this->referenceOperations()['all']);
    }

    /** @return array{period: list<PortfolioOperation>, all: list<PortfolioOperation>} */
    private function referenceOperations(): array
    {
        $period = [
            $this->operation('10', OperationType::DEPOSIT, '2026-03-10', 1, gross: '1000.00'),
            $this->operation('11', OperationType::BUY, '2026-03-10', 2, instrument: self::ETF, quantity: '8', gross: '800.00', fees: '2.00'),
            $this->operation('12', OperationType::DIVIDEND, '2026-03-15', 1, instrument: self::ETF, gross: '30.00', taxes: '9.00'),
            $this->operation('13', OperationType::SELL, '2026-03-20', 1, instrument: self::ETF, quantity: '10', gross: '1050.00', fees: '3.00'),
            $this->operation('14', OperationType::WITHDRAWAL, '2026-03-25', 1, gross: '200.00'),
            $this->operation('15', OperationType::FEE, '2026-03-28', 1, fees: '5.00'),
        ];
        $history = $this->operation('01', OperationType::BUY, '2026-02-01', 1, instrument: self::ETF, quantity: '92', gross: '8998.00');

        return ['period' => $period, 'all' => [$history, ...$period]];
    }

    /**
     * @param list<PortfolioOperation>      $operations
     * @param list<PortfolioOperation>|null $history
     */
    private function calculate(PeriodAnchor $start, PeriodAnchor $end, array $operations, ?array $history = null): TotalGain
    {
        $ledger = (new PositionLedger())->replay($history ?? $operations, [self::ETF => 6, self::STOCK => 6]);

        return (new TotalGainCalculator())->calculate($start, $end, $operations, $ledger, 2);
    }

    /**
     * @param list<PositionSnapshot> $positions
     * @param list<string>           $flags
     */
    private function anchor(string $date, string $total, ?string $securities = null, array $positions = [], array $flags = []): PeriodAnchor
    {
        return PeriodAnchor::valuation(
            '00000000-0000-7000-8000-0000000c'.substr(str_replace('-', '', $date), 2, 6).'0',
            new \DateTimeImmutable($date),
            DecimalValue::fromString($total),
            null === $securities ? DecimalValue::zero() : DecimalValue::fromString($securities),
            [] === $positions ? 'TOTAL' : 'POSITIONS',
            $flags,
            $positions,
        );
    }

    private function snapshot(string $quantity, ?string $cost): PositionSnapshot
    {
        return new PositionSnapshot(
            '00000000-0000-7000-8000-0000000e0001', '00000000-0000-7000-8000-0000000c0001', self::ETF,
            DecimalValue::fromString($quantity), null, null, null, null,
            null === $cost ? null : DecimalValue::fromString($cost), PriceQuality::CURRENT,
        );
    }

    private function operation(
        string $suffix,
        OperationType $type,
        string $date,
        int $sequence = 1,
        ?string $instrument = null,
        ?string $quantity = null,
        ?string $gross = null,
        ?string $fees = null,
        ?string $taxes = null,
        ?string $counter = null,
        ?string $counterQuantity = null,
    ): PortfolioOperation {
        $trade = in_array($type, [OperationType::BUY, OperationType::SELL], true);
        $details = new OperationDetails(
            $type, new \DateTimeImmutable($date), null, $instrument, $counter,
            null === $quantity ? null : DecimalValue::fromString($quantity),
            $trade ? DecimalValue::fromString('1') : null,
            null === $gross ? null : DecimalValue::fromString($gross),
            null === $fees ? ($trade ? DecimalValue::zero() : null) : DecimalValue::fromString($fees),
            null === $taxes ? ($trade ? DecimalValue::zero() : null) : DecimalValue::fromString($taxes),
            null, null === $counterQuantity ? null : DecimalValue::fromString($counterQuantity), null, null, null, null, null, null,
        );

        return new PortfolioOperation(
            '00000000-0000-7000-8000-0000000000'.$suffix,
            WorkspaceScope::fromString('00000000-0000-7000-8000-000000000001'), self::ACCOUNT, AssetCode::fromString('EUR'),
            $details, $sequence, DecimalValue::zero(), null, OperationState::ACTIVE, null, null, null, null,
            null, new \DateTimeImmutable('2026-01-01'), '00000000-0000-7000-8000-000000000002', 1,
        );
    }
}
