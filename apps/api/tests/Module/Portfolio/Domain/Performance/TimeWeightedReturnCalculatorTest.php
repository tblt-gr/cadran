<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Domain\Performance;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Domain\OperationDetails;
use App\Module\Portfolio\Domain\OperationState;
use App\Module\Portfolio\Domain\OperationType;
use App\Module\Portfolio\Domain\Performance\PerformanceReason;
use App\Module\Portfolio\Domain\Performance\PeriodAnchor;
use App\Module\Portfolio\Domain\Performance\ReturnChain;
use App\Module\Portfolio\Domain\Performance\TimeWeightedReturnCalculator;
use App\Module\Portfolio\Domain\PortfolioOperation;
use PHPUnit\Framework\TestCase;

final class TimeWeightedReturnCalculatorTest extends TestCase
{
    private const string ACCOUNT = '00000000-0000-7000-8000-0000000000d1';

    public function testHandComputedReferenceMonth(): void
    {
        $result = (new TimeWeightedReturnCalculator())->calculate($this->anchor('2026-02-28', '10000', []), $this->anchor('2026-03-31', '11050', []), [
            $this->flow('10', OperationType::DEPOSIT, '2026-03-10', '1000'),
            $this->flow('11', OperationType::WITHDRAWAL, '2026-03-25', '200'),
        ], [$this->anchor('2026-03-10', '11100', []), $this->anchor('2026-03-25', '10950', [])], [], 2);
        self::assertSame('0.023814842239499773746350', $result->rate?->toString());
        self::assertSame(['0.010000', '0.004505', '0.009132'], array_column($result->subperiods, 'value'));
        self::assertSame(3, $result->subperiodCount);
    }

    public function testMissingAndStaleDatesAreBlockingAndBounded(): void
    {
        $start = $this->anchor('2026-02-28', '10000', []);
        $end = $this->anchor('2026-03-31', '11000', []);
        $flow = $this->flow('10', OperationType::DEPOSIT, '2026-03-10', '1000');
        foreach ([[], [$this->anchor('2026-03-10', '11000', ['STALE_PRICES'])]] as $index => $values) {
            $result = (new TimeWeightedReturnCalculator())->calculate($start, $end, [$flow], $values, [], 2);
            self::assertNull($result->rate);
            self::assertSame(1, $result->missingCount);
            self::assertSame(0 === $index ? PerformanceReason::MISSING_FLOW_VALUATION : PerformanceReason::STALE_FLOW_VALUATION, $result->blocking[0]->code);
            self::assertSame(['2026-03-10'], $result->blocking[0]->dates);
        }
    }

    public function testNetZeroFlowsDoNotRequireAValuationAndEndDateFlowsUseTheEnd(): void
    {
        $calculator = new TimeWeightedReturnCalculator();
        $start = $this->anchor('2026-02-28', '100', []);
        $end = $this->anchor('2026-03-31', '110', []);
        $flows = [$this->flow('10', OperationType::DEPOSIT, '2026-03-10', '50'), $this->flow('11', OperationType::WITHDRAWAL, '2026-03-10', '50')];
        self::assertSame('0.100000000000000000000000', $calculator->calculate($start, $end, $flows, [], [], 2)->rate?->toString());
        $flows[] = $this->flow('12', OperationType::DEPOSIT, '2026-03-31', '10');
        self::assertSame('0.000000000000000000000000', $calculator->calculate($start, $end, $flows, [], [], 2)->rate?->toString());
    }

    public function testZeroStartOnlyAllowsZeroAdjustedEnd(): void
    {
        foreach (['0', '10'] as $endValue) {
            $result = (new TimeWeightedReturnCalculator())->calculate(PeriodAnchor::opening(new \DateTimeImmutable('2026-02-28')), $this->anchor('2026-03-31', $endValue, []), [], [], [], 2);
            if ('0' === $endValue) {
                self::assertTrue($result->rate?->isZero());
            } else {
                self::assertNull($result->rate);
                self::assertSame(PerformanceReason::ZERO_START_VALUE, $result->blocking[0]->code);
            }
        }
    }

    public function testSubperiodBoundAndResponseBound(): void
    {
        $start = $this->anchor('2000-01-01', '100', []);
        $anchors = [];
        for ($index = 1; $index <= 2001; ++$index) {
            $anchors[] = $this->anchor($start->date->modify('+'.$index.' days')->format('Y-m-d'), '100', []);
        }
        $end = array_pop($anchors);
        $result = (new TimeWeightedReturnCalculator())->calculate($start, $end, [], $anchors, $anchors, 2);
        self::assertNull($result->rate);
        self::assertSame(2001, $result->subperiodCount);
        self::assertSame(PerformanceReason::TOO_MANY_SUBPERIODS, $result->blocking[0]->code);
        $end = array_pop($anchors);
        self::assertNotNull($end);
        $result = (new TimeWeightedReturnCalculator())->calculate($start, $end, [], $anchors, $anchors, 2);
        self::assertTrue($result->rate?->isZero());
        self::assertCount(400, $result->subperiods);
        self::assertSame(2000, $result->subperiodCount);
    }

    public function testYearAgreesWithChainedMonthsAtWorkingPrecision(): void
    {
        $calculator = new TimeWeightedReturnCalculator();
        $start = $this->anchor('2025-12-31', '10000', []);
        $previous = $start;
        $rates = [];
        $anchors = [];
        $boundaries = [];
        $allFlows = [];
        for ($month = 1; $month <= 12; ++$month) {
            $first = new \DateTimeImmutable(sprintf('2026-%02d-01', $month));
            $flows = [];
            $values = [];
            if ($month <= 3) {
                $date = $first->modify('+9 days')->format('Y-m-d');
                $flows[] = $this->flow((string) (10 + $month), OperationType::DEPOSIT, $date, '100');
                $values[] = $this->anchor($date, (string) (10000 + ($month - 1) * 200 + 150), []);
            }
            $end = $this->anchor($first->modify('last day of this month')->format('Y-m-d'), (string) (10000 + min($month, 3) * 200), []);
            $result = $calculator->calculate($previous, $end, $flows, $values, [], 2);
            self::assertNotNull($result->rate);
            $rates[] = $result->rate;
            $anchors = [...$anchors, ...$values, $end];
            $boundaries[] = $end;
            $allFlows = [...$allFlows, ...$flows];
            $previous = $end;
        }
        $year = $calculator->calculate($start, $previous, $allFlows, $anchors, $boundaries, 2);
        self::assertNotNull($year->rate);
        $difference = $year->rate->subtract(ReturnChain::chain($rates));
        $tolerance = \App\Module\Foundation\Domain\CalculatedDecimal::fromDecimal(DecimalValue::fromString('0.00000000000000000001'));
        self::assertLessThanOrEqual(0, $difference->compareTo($tolerance));
        self::assertGreaterThanOrEqual(0, $difference->compareTo(\App\Module\Foundation\Domain\CalculatedDecimal::zero()->subtract($tolerance)));
    }

    public function testMissingCountIsExactWhileDatesAreCapped(): void
    {
        $flows = [];
        for ($day = 1; $day <= 30; ++$day) {
            $flows[] = $this->flow((string) (10 + $day), OperationType::DEPOSIT, sprintf('2026-03-%02d', $day), '1');
        }
        $result = (new TimeWeightedReturnCalculator())->calculate($this->anchor('2026-02-28', '100', []), $this->anchor('2026-03-31', '130', []), $flows, [], [], 2);
        self::assertSame(30, $result->missingCount);
        self::assertCount(20, $result->blocking[0]->dates);
    }

    public function testStaleAnchorsBlockAndWarningsCarryThrough(): void
    {
        $calculator = new TimeWeightedReturnCalculator();
        $start = $this->anchor('2026-02-28', '100', ['STALE_PRICES']);
        $end = $this->anchor('2026-03-31', '110', []);
        $stale = $calculator->calculate($start, $end, [], [], [], 2);
        self::assertNull($stale->rate);
        self::assertSame(PerformanceReason::STALE_FLOW_VALUATION, $stale->blocking[0]->code);
        $warning = $calculator->calculate($this->anchor('2026-02-28', '100', []), $end, [$this->flow('10', OperationType::DEPOSIT, '2026-03-10', '10')], [$this->anchor('2026-03-10', '110', ['CASH_DIFFERENCE'])], [], 2);
        self::assertNotNull($warning->rate);
        self::assertSame(PerformanceReason::CASH_DIFFERENCE, $warning->warnings[0]->code);
    }

    public function testLargeDecimalsRetainExactInputsAndTransfersBlock(): void
    {
        $calculator = new TimeWeightedReturnCalculator();
        $start = $this->anchor('2026-02-28', '99999999999999999999999999.123456789012345678901234', []);
        $end = $this->anchor('2026-03-31', '99999999999999999999999999.123456789012345678901234', []);
        $result = $calculator->calculate($start, $end, [], [], [], 2);
        self::assertTrue($result->rate?->isZero());
        self::assertSame($start->totalValue->toString(), $result->subperiods[0]['startValue']);
        $transfer = $this->operation('10', OperationType::SECURITY_TRANSFER_IN, '2026-03-10', null, '00000000-0000-7000-8000-0000000000a1', '1');
        $result = $calculator->calculate($start, $end, [$transfer], [], [], 2);
        self::assertNull($result->rate);
        self::assertSame(PerformanceReason::UNVALUED_SECURITY_TRANSFER, $result->blocking[0]->code);
    }

    /** @param list<string> $flags */
    private function anchor(string $date, string $total, array $flags): PeriodAnchor
    {
        return PeriodAnchor::valuation(
            '00000000-0000-7000-8000-0000000c'.substr(str_replace('-', '', $date), 2, 6).'0',
            new \DateTimeImmutable($date), DecimalValue::fromString($total), DecimalValue::zero(), 'TOTAL', $flags, [],
        );
    }

    private function flow(string $suffix, OperationType $type, string $date, string $gross): PortfolioOperation
    {
        return $this->operation($suffix, $type, $date, $gross);
    }

    private function operation(string $suffix, OperationType $type, string $date, ?string $gross, ?string $instrument = null, ?string $quantity = null): PortfolioOperation
    {
        $details = new OperationDetails(
            $type, new \DateTimeImmutable($date), null, $instrument, null, null === $quantity ? null : DecimalValue::fromString($quantity), null,
            null === $gross ? null : DecimalValue::fromString($gross),
            null, null, null, null, null, null, null, null, null, null,
        );

        return new PortfolioOperation(
            '00000000-0000-7000-8000-0000000000'.$suffix,
            WorkspaceScope::fromString('00000000-0000-7000-8000-000000000001'), self::ACCOUNT, AssetCode::fromString('EUR'),
            $details, 1, DecimalValue::zero(), null, OperationState::ACTIVE, null, null, null, null,
            null, new \DateTimeImmutable('2026-01-01'), '00000000-0000-7000-8000-000000000002', 1,
        );
    }
}
