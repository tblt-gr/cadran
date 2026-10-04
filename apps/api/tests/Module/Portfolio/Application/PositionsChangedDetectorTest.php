<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Application;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Portfolio\Application\PositionsChangedDetector;
use App\Module\Portfolio\Application\ReconcilePortfolioPositions;
use App\Module\Portfolio\Domain\AssetClass;
use App\Module\Portfolio\Domain\Instrument;
use App\Module\Portfolio\Domain\InstrumentRepository;
use App\Module\Portfolio\Domain\InstrumentSource;
use App\Module\Portfolio\Domain\InstrumentStatus;
use App\Module\Portfolio\Domain\InstrumentType;
use App\Module\Portfolio\Domain\OperationDetails;
use App\Module\Portfolio\Domain\OperationState;
use App\Module\Portfolio\Domain\OperationType;
use App\Module\Portfolio\Domain\PortfolioOperation;
use App\Module\Portfolio\Domain\PortfolioOperationRepository;
use App\Module\Portfolio\Domain\Positions\PositionLedger;
use App\Module\Portfolio\Domain\Ticker;
use App\Module\Portfolio\Domain\Valuation\CashSource;
use App\Module\Portfolio\Domain\Valuation\PositionSnapshot;
use App\Module\Portfolio\Domain\Valuation\PriceQuality;
use App\Module\Portfolio\Domain\Valuation\Valuation;
use App\Module\Portfolio\Domain\Valuation\ValuationCompleteness;
use App\Module\Portfolio\Domain\Valuation\ValuationDetail;
use App\Module\Portfolio\Domain\Valuation\ValuationSource;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PositionsChangedDetectorTest extends TestCase
{
    private const string ACCOUNT = '00000000-0000-7000-8000-0000000000d1';
    private const string ETF = '00000000-0000-7000-8000-000000000b01';

    #[Test]
    public function aSingleReplayOfTheOperationsFlagsOnlyTheValuationsTheHistoryChanged(): void
    {
        $operations = $this->createMock(PortfolioOperationRepository::class);
        $operations->expects(self::once())->method('activeForAccount')->willReturn([
            $this->buy('01', '2026-01-02', '10'),
            $this->buy('02', '2026-02-10', '5'),
        ]);
        $detector = $this->detector($operations);

        $changed = $detector->changedAmong(WorkspaceFixture::own(), self::ACCOUNT, [
            $this->valuation('c1', '2026-01-31', '10'),
            $this->valuation('c2', '2026-02-28', '10'),
            $this->valuation('c3', '2026-03-31', '15'),
        ]);

        self::assertSame(['c1' => false, 'c2' => true, 'c3' => false], $changed);
    }

    #[Test]
    public function anInstrumentHeldThenSoldOrNeverFrozenIsAChange(): void
    {
        $operations = $this->createStub(PortfolioOperationRepository::class);
        $operations->method('activeForAccount')->willReturn([$this->buy('01', '2026-01-02', '10')]);

        $changed = $this->detector($operations)->changedAmong(WorkspaceFixture::own(), self::ACCOUNT, [
            $this->valuation('c1', '2026-01-31'),
            $this->valuation('c2', '2026-01-01', '10'),
        ]);

        self::assertSame(['c1' => true, 'c2' => true], $changed);
    }

    #[Test]
    public function aValuationAfterAnUnreplayableOperationIsFlaggedAndTheOneBeforeIsNot(): void
    {
        $operations = $this->createStub(PortfolioOperationRepository::class);
        $operations->method('activeForAccount')->willReturn([
            $this->buy('01', '2026-01-02', '10'),
            $this->operation('02', OperationType::SELL, '2026-02-10', '30', gross: '300.00'),
        ]);

        $changed = $this->detector($operations)->changedAmong(WorkspaceFixture::own(), self::ACCOUNT, [
            $this->valuation('c1', '2026-01-31', '10'),
            $this->valuation('c2', '2026-02-28', '10'),
        ]);

        self::assertSame(['c1' => false, 'c2' => true], $changed);
    }

    #[Test]
    public function noValuationMeansNoReplay(): void
    {
        $operations = $this->createMock(PortfolioOperationRepository::class);
        $operations->expects(self::never())->method('activeForAccount');

        self::assertSame([], $this->detector($operations)->changedAmong(WorkspaceFixture::own(), self::ACCOUNT, []));
    }

    private function detector(PortfolioOperationRepository $operations): PositionsChangedDetector
    {
        $now = new \DateTimeImmutable('2026-01-01T10:00:00Z');
        $instruments = $this->createStub(InstrumentRepository::class);
        $instruments->method('findMany')->willReturn([new Instrument(
            self::ETF, WorkspaceFixture::own(), 'ETF', null, Ticker::fromString('WLD'), null, InstrumentType::ETF, AssetClass::EQUITY, null,
            AssetCode::fromString('EUR'), 6, 4, InstrumentSource::MANUAL, $now, WorkspaceFixture::OWNER_ID, InstrumentStatus::ACTIVE,
            null, $now, WorkspaceFixture::OWNER_ID, $now, 1,
        )]);

        return new PositionsChangedDetector(new ReconcilePortfolioPositions($operations, $instruments, new PositionLedger()));
    }

    private function buy(string $suffix, string $date, string $quantity): PortfolioOperation
    {
        return $this->operation($suffix, OperationType::BUY, $date, $quantity, gross: '100.00');
    }

    private function operation(string $suffix, OperationType $type, string $date, string $quantity, string $gross): PortfolioOperation
    {
        $details = new OperationDetails(
            $type, new \DateTimeImmutable($date), null, self::ETF, null, DecimalValue::fromString($quantity), DecimalValue::fromString('1'),
            DecimalValue::fromString($gross), DecimalValue::zero(), DecimalValue::zero(), null, null, null, null, null, null, null, null,
        );

        return new PortfolioOperation(
            '00000000-0000-7000-8000-0000000000'.$suffix, WorkspaceFixture::own(), self::ACCOUNT, AssetCode::fromString('EUR'),
            $details, 1, DecimalValue::zero(), null, OperationState::ACTIVE, null, null, null, null,
            null, new \DateTimeImmutable('2026-01-01'), WorkspaceFixture::OWNER_ID, 1,
        );
    }

    private function valuation(string $id, string $valuedOn, ?string $frozenQuantity = null): Valuation
    {
        $snapshots = null === $frozenQuantity ? [] : [new PositionSnapshot(
            '00000000-0000-7000-8000-0000000000f1', $id, self::ETF, DecimalValue::fromString($frozenQuantity),
            null, null, null, null, null, PriceQuality::CURRENT,
        )];

        return new Valuation(
            id: $id, workspace: WorkspaceFixture::own(), accountId: self::ACCOUNT, valuedOn: new \DateTimeImmutable($valuedOn, new \DateTimeZone('UTC')),
            assetCode: AssetCode::fromString('EUR'), cash: DecimalValue::zero(), derivedCash: DecimalValue::zero(), cashSource: CashSource::DERIVED,
            securitiesValue: null, totalValue: null, observedTotal: null, detail: ValuationDetail::POSITIONS, completeness: ValuationCompleteness::PARTIAL,
            source: ValuationSource::MANUAL, note: null, supersedesId: null, supersededAt: null, withdrawn: false,
            recordedAt: new \DateTimeImmutable('2026-04-01 09:00:00+00'), recordedBy: WorkspaceFixture::OWNER_ID, version: 1, positions: $snapshots,
        );
    }
}
