<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Domain;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Portfolio\Domain\Cash\CashLedger;
use App\Module\Portfolio\Domain\InvalidPortfolioOperation;
use App\Module\Portfolio\Domain\LegDirection;
use App\Module\Portfolio\Domain\OperationType;
use App\Module\Portfolio\Domain\Performance\DecompositionStatus;
use App\Module\Portfolio\Domain\Performance\ExternalFlow;
use App\Module\Portfolio\Domain\Performance\PeriodAnchor;
use App\Module\Portfolio\Domain\Performance\TotalGainCalculator;
use App\Module\Portfolio\Domain\Positions\PositionLedger;
use App\Module\Portfolio\Domain\Valuation\PositionSnapshot;
use App\Module\Portfolio\Domain\Valuation\PriceQuality;
use App\Tests\Support\InsuranceOperationFixture as Insurance;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

final class LifeInsuranceOperationTest extends TestCase
{
    public function testReferenceCashPositionsCostBasesAndSignedExternalFlows(): void
    {
        $premium = Insurance::premium();
        $surrender = Insurance::surrender();
        $ledger = new PositionLedger();
        $opening = $ledger->replay([$premium], []);
        self::assertSame(0, BigDecimal::of('6000.00')->compareTo($opening->positions[Insurance::FUND]->averageCostBasis?->toString() ?? '-1'));
        self::assertSame(0, BigDecimal::of('3900.00')->compareTo($opening->positions[Insurance::EQUITY]->averageCostBasis?->toString() ?? '-1'));
        self::assertSame('10000.00', ExternalFlow::signedAmount($premium)?->toString());
        self::assertSame('-1033.90', ExternalFlow::signedAmount($surrender)?->toString());
        $closing = $ledger->replay([$surrender, $premium], []);
        self::assertSame(0, BigDecimal::of('30')->compareTo($closing->positions[Insurance::EQUITY]->quantity->toString()));
        self::assertSame(0, BigDecimal::of('2925.00')->compareTo($closing->positions[Insurance::EQUITY]->averageCostBasis?->toString() ?? '-1'));
        self::assertSame(0, BigDecimal::of('975.00')->compareTo($closing->averageCostReleased[$surrender->id.':1']?->toString() ?? '-1'));
        self::assertSame('0.00', CashLedger::replay([], [$premium, $surrender])->closingBalance->toString());
    }

    public function testPerformanceDecompositionIncludesLegDisposalsEntryFeesAndObservedTaxes(): void
    {
        // End holdings: 6000 euro fund + 30 x 105 = 9150. Net flows: 10000 - 1033.90 = 8966.10.
        // Gain 183.90 = realized 75 + unrealized 225 - entry fees 100 - withholdings 16.10.
        $operations = [Insurance::premium(), Insurance::surrender()];
        $positions = [];
        foreach ([[Insurance::FUND, '6000', '6000'], [Insurance::EQUITY, '30', '2925']] as [$instrument, $quantity, $cost]) {
            $positions[] = new PositionSnapshot('snapshot-'.$instrument, 'valuation', $instrument, DecimalValue::fromString($quantity), null, null, null, null, DecimalValue::fromString($cost), PriceQuality::CURRENT);
        }
        $end = PeriodAnchor::valuation('valuation', new \DateTimeImmutable('2026-06-30'), DecimalValue::fromString('9150.00'), DecimalValue::fromString('9150.00'), 'POSITIONS', [], $positions);
        $result = (new TotalGainCalculator())->calculate(PeriodAnchor::opening(new \DateTimeImmutable('2026-01-14')), $end, $operations, (new PositionLedger())->replay($operations, []), 2);
        self::assertSame('8966.10', $result->netFlows);
        self::assertSame('183.90', $result->gain);
        self::assertSame(DecompositionStatus::COMPLETE, $result->decomposition->status);
        self::assertSame('75.00', $result->decomposition->realized);
        self::assertSame('225.00', $result->decomposition->unrealizedChange);
        self::assertSame('-100.00', $result->decomposition->fees);
        self::assertSame('-16.10', $result->decomposition->taxes);
        self::assertSame('0.00', $result->decomposition->residual);
    }

    public function testExactLegStoresANullResidualRatherThanZero(): void
    {
        $leg = Insurance::leg(1, LegDirection::IN, Insurance::FUND, '6000.00', '1', '6000.00');
        self::assertNull($leg->roundingResidual);
        self::assertNull(Insurance::premium()->details->legs[0]->roundingResidual);
        self::assertNull(Insurance::premium()->details->legs[1]->roundingResidual);
    }

    public function testHalfCentResidualIsKeptAndBeyondItReturnsExpectedAmount(): void
    {
        $leg = Insurance::leg(1, LegDirection::IN, Insurance::EQUITY, '1', '97.505', '97.50');
        self::assertSame('-0.005', $leg->roundingResidual?->toString());
        try {
            Insurance::leg(1, LegDirection::IN, Insurance::EQUITY, '1', '97.5051', '97.50');
            self::fail('Beyond half a cent must be refused.');
        } catch (InvalidPortfolioOperation $exception) {
            self::assertSame('leg_amount_mismatch', $exception->problemCode);
            self::assertSame('97.5051', $exception->extensions['expectedAmount']);
        }
    }

    public function testKnownZeroNetPaidAndMissingNetOnAPremiumRemainDistinct(): void
    {
        self::assertNull(Insurance::premium()->details->netPaidAmount());
        $surrender = Insurance::operation(OperationType::PARTIAL_SURRENDER, '10.00', fees: '2.00', incomeTax: '3.00', socialTax: '5.00');
        self::assertSame('0.00', $surrender->details->netPaidAmount()?->toString());
        self::assertSame('0.00', ExternalFlow::signedAmount($surrender)?->toString());
        self::assertTrue(ExternalFlow::isExternal($surrender));
    }

    public function testVeryLargeStorableAmountsStayExact(): void
    {
        $large = '99999999999999999999999999.99';
        $premium = Insurance::operation(OperationType::PREMIUM, $large, fees: '0.01');
        self::assertSame('99999999999999999999999999.98', $premium->cashAmount->toString());
    }

    public function testRandomSequencesPreserveTheCashIdentityAndPositionQuantities(): void
    {
        mt_srand(273);
        for ($sample = 0; $sample < 20; ++$sample) {
            $operations = [];
            $cashCents = $supportCents = 0;
            for ($index = 1; $index <= 30; ++$index) {
                if (0 !== $index % 3 || 0 === $cashCents + $supportCents) {
                    $gross = mt_rand(1000, 200000);
                    $fees = mt_rand(0, 100);
                    $bought = mt_rand(0, $gross - $fees);
                    $legs = 0 === $bought ? [] : [Insurance::leg(1, LegDirection::IN, Insurance::FUND, self::cents($bought), '1', self::cents($bought))];
                    $operations[] = Insurance::operation(OperationType::PREMIUM, self::cents($gross), $legs, self::cents($fees), sequence: $index);
                    $cashCents += $gross - $fees - $bought;
                    $supportCents += $bought;
                } else {
                    $gross = mt_rand(1, $cashCents + $supportCents);
                    $sold = min($gross, $supportCents);
                    $fees = mt_rand(0, min($gross, 20));
                    $tax = mt_rand(0, min($gross - $fees, 20));
                    $legs = 0 === $sold ? [] : [Insurance::leg(1, LegDirection::OUT, Insurance::FUND, self::cents($sold), '1', self::cents($sold))];
                    $operations[] = Insurance::operation(OperationType::PARTIAL_SURRENDER, self::cents($gross), $legs, self::cents($fees), self::cents($tax), sequence: $index);
                    $cashCents += $sold - $gross;
                    $supportCents -= $sold;
                }
            }
            $ledger = CashLedger::replay([], $operations);
            $total = $ledger->totals;
            $identity = BigDecimal::of($ledger->openingBalance->toString())->plus($total->deposits->toString())->minus($total->withdrawals->toString())
                ->minus($total->buysGross->toString())->plus($total->sellsGross->toString())->minus($total->supportsBought->toString())->plus($total->supportsSold->toString())
                ->plus($total->dividendsGross->toString())->plus($total->interestGross->toString())->minus($total->fees->toString())->minus($total->taxes->toString())->plus($total->cashInLieu->toString());
            self::assertSame(0, $identity->compareTo($ledger->closingBalance->toString()));
            self::assertSame(0, BigDecimal::of(self::cents($cashCents))->compareTo($ledger->closingBalance->toString()));
            $position = (new PositionLedger())->replay($operations, [Insurance::FUND => 2])->positions[Insurance::FUND];
            self::assertSame(0, BigDecimal::of(self::cents($supportCents))->compareTo($position->quantity->toString()));
        }
    }

    private static function cents(int $value): string
    {
        return intdiv($value, 100).'.'.str_pad((string) ($value % 100), 2, '0', STR_PAD_LEFT);
    }
}
