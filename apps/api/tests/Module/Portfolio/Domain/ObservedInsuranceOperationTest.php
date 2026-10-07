<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Domain;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Portfolio\Domain\Cash\CashLedger;
use App\Module\Portfolio\Domain\OperationType;
use App\Module\Portfolio\Domain\Positions\PositionLedger;
use App\Tests\Support\InsuranceOperationFixture as Insurance;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

final class ObservedInsuranceOperationTest extends TestCase
{
    public function testObservedInterestAndManagementFeesKeepCashUnchanged(): void
    {
        // 150 gross - 25.80 withheld = 124.20 credited units and cost.
        $interest = Insurance::observed(OperationType::EURO_FUND_INTEREST, Insurance::FUND, gross: '150.00', taxes: '25.80');
        $euroFee = Insurance::observed(OperationType::MANAGEMENT_FEE, Insurance::FUND, fees: '12.00', sequence: 3);
        $unitFee = Insurance::observed(OperationType::MANAGEMENT_FEE, Insurance::EQUITY, fees: '30.00', quantity: '0.30', price: '100.00', sequence: 4);
        $operations = [Insurance::premium(), $interest, $euroFee, $unitFee];
        $ledger = (new PositionLedger())->replay($operations, []);
        self::assertSame(0, BigDecimal::of('6112.20')->compareTo($ledger->positions[Insurance::FUND]->quantity->toString()));
        self::assertSame(0, BigDecimal::of('6112.20')->compareTo($ledger->positions[Insurance::FUND]->averageCostBasis?->toString() ?? '-1'));
        self::assertSame(0, BigDecimal::of('39.70')->compareTo($ledger->positions[Insurance::EQUITY]->quantity->toString()));
        self::assertSame(0, BigDecimal::of('29.25')->compareTo($ledger->averageCostReleased[$unitFee->id]?->toString() ?? '-1'));
        $cash = CashLedger::replay([], $operations);
        self::assertSame(0, BigDecimal::of('0')->compareTo($cash->closingBalance->toString()));
        self::assertSame('150.00', $cash->totals->interestGross->toString());
        self::assertSame('25.80', $cash->totals->taxes->toString());
        self::assertSame('142.00', $cash->totals->fees->toString());
    }

    public function testPerformanceReferenceHasNoExternalIncomeFlowAndNoResidual(): void
    {
        $interest = Insurance::observed(OperationType::EURO_FUND_INTEREST, Insurance::FUND, gross: '150.00', taxes: '25.80');
        $operations = [Insurance::premium(), $interest,
            Insurance::observed(OperationType::MANAGEMENT_FEE, Insurance::FUND, fees: '12.00', sequence: 3),
            Insurance::observed(OperationType::MANAGEMENT_FEE, Insurance::EQUITY, fees: '30.00', quantity: '0.30', sequence: 4)];
        self::assertNull(\App\Module\Portfolio\Domain\Performance\ExternalFlow::signedAmount($interest));
        $positions = [];
        foreach ([[Insurance::FUND, '6112.20', '6112.20'], [Insurance::EQUITY, '39.70', '3870.75']] as [$id, $quantity, $cost]) {
            $positions[] = new \App\Module\Portfolio\Domain\Valuation\PositionSnapshot('snapshot-'.$id, 'valuation', $id, DecimalValue::fromString($quantity), null, null, null, null, DecimalValue::fromString($cost), \App\Module\Portfolio\Domain\Valuation\PriceQuality::CURRENT);
        }
        $end = \App\Module\Portfolio\Domain\Performance\PeriodAnchor::valuation('valuation', new \DateTimeImmutable('2026-12-31'), DecimalValue::fromString('10082.20'), DecimalValue::fromString('10082.20'), 'POSITIONS', [], $positions);
        $result = (new \App\Module\Portfolio\Domain\Performance\TotalGainCalculator())->calculate(\App\Module\Portfolio\Domain\Performance\PeriodAnchor::opening(new \DateTimeImmutable('2026-01-14')), $end, $operations, (new PositionLedger())->replay($operations, []), 2);
        self::assertSame('82.20', $result->gain);
        self::assertSame('150.00', $result->decomposition->income);
        self::assertSame('-142.00', $result->decomposition->fees);
        self::assertSame('-25.80', $result->decomposition->taxes);
        self::assertSame('0.75', $result->decomposition->realized);
        self::assertSame('0.00', $result->decomposition->residual);
    }

    public function testQualityCountsAndSourcesAreCappedWithoutTruncatingAmounts(): void
    {
        $operations = [];
        for ($index = 1; $index <= 25; ++$index) {
            $operations[] = Insurance::observed(OperationType::PROFIT_SHARING, Insurance::FUND, gross: '1.00', sequence: $index, basis: 'UNKNOWN');
        }
        $result = \App\Module\Portfolio\Domain\LifeInsurance\IncomeAndFeesCalculator::calculate(2026, 'EUR', $operations, []);
        self::assertSame(25, $result['sourceCount']);
        self::assertCount(20, $result['sources']);
        self::assertCount(2, $result['qualityIssues']);
        foreach ($result['qualityIssues'] as $issue) {
            self::assertSame(25, $issue['count']);
            self::assertCount(20, $issue['operationIds']);
        }
        self::assertSame('25.00', $result['profitSharing']['net']);
    }

    public function testSurrenderMatrixRefusesInterestFields(): void
    {
        foreach ([OperationType::PARTIAL_SURRENDER, OperationType::FULL_SURRENDER] as $type) {
            foreach (['interestBasis', 'rateYear'] as $field) {
                try {
                    \App\Module\Portfolio\Domain\OperationFieldMatrix::assertPresence($type, ['grossAmount' => true, $field => true]);
                    self::fail('Surrenders cannot carry interest-only evidence.');
                } catch (\App\Module\Portfolio\Domain\InvalidPortfolioOperation $exception) {
                    self::assertSame('operation_field_not_allowed', $exception->problemCode);
                    self::assertSame($field, $exception->extensions['field']);
                }
            }
        }
    }

    public function testInsignificantZerosNeverEscapeIntoDerivedMovementQuantities(): void
    {
        $interest = Insurance::observed(OperationType::EURO_FUND_INTEREST, Insurance::FUND, gross: '1.001', taxes: '0.001');
        $ledger = new PositionLedger();
        $credit = $ledger->replay([$interest], []);
        self::assertSame('1', $credit->positions[Insurance::FUND]->quantity->toString());
        self::assertSame('1.000', $interest->details->netCreditedAmount()?->toString());
        $fee = Insurance::observed(OperationType::MANAGEMENT_FEE, Insurance::FUND, fees: '0.010', sequence: 3);
        $tax = Insurance::observed(OperationType::SOCIAL_CONTRIBUTION, Insurance::FUND, taxes: '0.010', sequence: 4);
        $closing = $ledger->replay([$interest, $fee, $tax], []);
        self::assertSame('0.98', $closing->positions[Insurance::FUND]->quantity->toString());
        self::assertSame('-0.01', $closing->movements[Insurance::FUND][1]->quantityDelta->toString());
        self::assertSame('-0.01', $closing->movements[Insurance::FUND][2]->quantityDelta->toString());
    }
}
