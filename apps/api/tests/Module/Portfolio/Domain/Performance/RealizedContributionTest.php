<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Domain\Performance;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\CalculatedDecimal;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Domain\LegDirection;
use App\Module\Portfolio\Domain\OperationDetails;
use App\Module\Portfolio\Domain\OperationLeg;
use App\Module\Portfolio\Domain\OperationState;
use App\Module\Portfolio\Domain\OperationType;
use App\Module\Portfolio\Domain\Performance\RealizedContribution;
use App\Module\Portfolio\Domain\PortfolioOperation;
use App\Module\Portfolio\Domain\Positions\PositionLedgerResult;
use PHPUnit\Framework\TestCase;

final class RealizedContributionTest extends TestCase
{
    private const string ACCOUNT = '00000000-0000-7000-8000-0000000000d1';
    private const string ETF = '00000000-0000-7000-8000-000000000b01';
    private const string STOCK = '00000000-0000-7000-8000-000000000b02';

    public function testSaleUsesTheExactReleasedAverageCostAndFiltersTheInstrument(): void
    {
        $sale = $this->operation('10', OperationType::SELL, '2026-03-04', instrument: self::ETF, quantity: '4', gross: '440.00');
        $ledger = new PositionLedgerResult([], [], [$sale->id => self::amount('400.80')]);
        self::assertSame('39.20', RealizedContribution::of($sale, $ledger, self::ETF)?->toString());
        self::assertTrue(RealizedContribution::of($sale, $ledger, self::STOCK)?->isZero());
        self::assertNull(RealizedContribution::of($sale, new PositionLedgerResult([], []), self::ETF));
    }

    public function testLargeValuesAndZeroAreExact(): void
    {
        $sale = $this->operation('10', OperationType::SELL, '2026-03-04', instrument: self::ETF, quantity: '1', gross: '99999999999999999999999999.99');
        $ledger = new PositionLedgerResult([], [], [$sale->id => self::amount('99999999999999999999999999.98')]);
        self::assertSame('0.01', RealizedContribution::of($sale, $ledger)?->toString());
        $ledger = new PositionLedgerResult([], [], [$sale->id => self::amount('99999999999999999999999999.99')]);
        self::assertTrue(RealizedContribution::of($sale, $ledger)?->isZero());
    }

    public function testSupportDeductionUsesTheSameRuleAsTheAccountDecomposition(): void
    {
        $fee = $this->operation('10', OperationType::MANAGEMENT_FEE, '2026-03-04', instrument: self::ETF, quantity: '1', fees: '12.00');
        $ledger = new PositionLedgerResult([], [], [$fee->id => self::amount('10.00')]);
        self::assertSame('2.00', RealizedContribution::of($fee, $ledger, self::ETF)?->toString());
        self::assertNull(RealizedContribution::of($fee, new PositionLedgerResult([], []), self::ETF));
    }

    public function testOutgoingLegsAreAttributedOnlyToTheirInstrument(): void
    {
        $legs = [new OperationLeg(1, LegDirection::OUT, self::ETF, DecimalValue::fromString('1'), DecimalValue::fromString('120.00'), DecimalValue::fromString('120.00'), 6, 4, 2),
            new OperationLeg(2, LegDirection::IN, self::STOCK, DecimalValue::fromString('1'), DecimalValue::fromString('120.00'), DecimalValue::fromString('120.00'), 6, 4, 2)];
        $details = new OperationDetails(OperationType::SWITCH, new \DateTimeImmutable('2026-03-04'), null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, $legs);
        $operation = $this->wrap($details);
        $ledger = new PositionLedgerResult([], [], [$operation->id.':1' => self::amount('100.00')]);
        self::assertSame('20.00', RealizedContribution::of($operation, $ledger)?->toString());
        self::assertSame('20.00', RealizedContribution::of($operation, $ledger, self::ETF)?->toString());
        self::assertTrue(RealizedContribution::of($operation, $ledger, self::STOCK)?->isZero());
        self::assertNull(RealizedContribution::of($operation, new PositionLedgerResult([], []), self::ETF));
    }

    private static function amount(string $value): CalculatedDecimal
    {
        return CalculatedDecimal::fromDecimal(DecimalValue::fromString($value));
    }

    private function wrap(OperationDetails $details): PortfolioOperation
    {
        return new PortfolioOperation('00000000-0000-7000-8000-000000000010', WorkspaceScope::fromString('00000000-0000-7000-8000-000000000001'), self::ACCOUNT, AssetCode::fromString('EUR'),
            $details, 1, DecimalValue::zero(), null, OperationState::ACTIVE, null, null, null, null, null, new \DateTimeImmutable('2026-01-01'), '00000000-0000-7000-8000-000000000002', 1);
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
