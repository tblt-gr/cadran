<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Domain\Performance;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Domain\OperationDetails;
use App\Module\Portfolio\Domain\OperationState;
use App\Module\Portfolio\Domain\OperationType;
use App\Module\Portfolio\Domain\Performance\ModifiedDietz;
use App\Module\Portfolio\Domain\Performance\ModifiedDietzCalculator;
use App\Module\Portfolio\Domain\Performance\PerformanceReason;
use App\Module\Portfolio\Domain\Performance\PeriodAnchor;
use App\Module\Portfolio\Domain\PortfolioOperation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ModifiedDietzCalculatorTest extends TestCase
{
    private const string ACCOUNT = '00000000-0000-7000-8000-0000000000d1';

    #[Test]
    public function itComputesTheReferenceMonthFromTheHandWorkedDenominator(): void
    {
        // T = 31, 10000.00 x 31 + 21 x 1000.00 + 6 x (-200.00) = 329800, gain 250.00.
        $result = $this->calculate('2026-02-28', '10000.00', '2026-03-31', '11050.00', [
            $this->flow('10', OperationType::DEPOSIT, '2026-03-10', '1000.00'),
            $this->flow('11', OperationType::WITHDRAWAL, '2026-03-25', '200.00'),
        ]);

        self::assertNull($result->reason);
        self::assertSame('0.023499090357792601576713', $result->rate?->toString());
        self::assertSame('10638.709677', $result->averageCapital);
        self::assertSame(2, $result->weightCount);
        self::assertSame(['21', '0.677419', '1000.00'], [$result->weights[0]['remainingDays'], $result->weights[0]['weight'], $result->weights[0]['amount']]);
        self::assertSame(['6', '0.193548', '-200.00'], [$result->weights[1]['remainingDays'], $result->weights[1]['weight'], $result->weights[1]['amount']]);
        self::assertSame('2026-03-10', $result->weights[0]['date']);
    }

    #[Test]
    public function itComputesAnOpeningWithADepositOnTheTenth(): void
    {
        $result = $this->calculate('2026-02-28', '0', '2026-03-31', '1010.00', [$this->flow('10', OperationType::DEPOSIT, '2026-03-10', '1000.00')], opening: true);

        self::assertNull($result->reason);
        self::assertSame('0.014761904761904761904762', $result->rate?->toString());
    }

    #[Test]
    public function itRefusesATooSmallDenominatorForADepositOnTheTwentyNinth(): void
    {
        $result = $this->calculate('2026-02-28', '0', '2026-03-31', '1010.00', [$this->flow('10', OperationType::DEPOSIT, '2026-03-29', '1000.00')], opening: true);

        self::assertNull($result->rate);
        self::assertSame(PerformanceReason::DENOMINATOR_TOO_SMALL, $result->reason);
        self::assertSame('64.516129', $result->averageCapital);
    }

    #[Test]
    public function itRefusesAnEmptyMonthAsNoInvestedCapital(): void
    {
        $result = $this->calculate('2026-02-28', '0', '2026-03-31', '0', [], opening: true);

        self::assertNull($result->rate);
        self::assertNull($result->averageCapital);
        self::assertSame(PerformanceReason::NO_INVESTED_CAPITAL, $result->reason);
    }

    #[Test]
    public function aFlowOnTheEndDateWeighsZero(): void
    {
        // 1000 x 31 + 0 x 500 = 31000, gain 1500 - 1000 - 500 = 0.
        $result = $this->calculate('2026-02-28', '1000.00', '2026-03-31', '1500.00', [$this->flow('10', OperationType::DEPOSIT, '2026-03-31', '500.00')]);

        self::assertSame('0', $result->weights[0]['remainingDays']);
        self::assertSame('0.000000', $result->weights[0]['weight']);
        self::assertSame('0.000000000000000000000000', $result->rate?->toString());
    }

    #[Test]
    public function aFlowTheDayAfterTheStartWeighsAlmostTheWholePeriod(): void
    {
        $result = $this->calculate('2026-02-28', '1000.00', '2026-03-31', '2000.00', [$this->flow('10', OperationType::DEPOSIT, '2026-03-01', '500.00')]);

        self::assertSame('30', $result->weights[0]['remainingDays']);
        self::assertSame('0.967742', $result->weights[0]['weight']);
        // 500 x 31 / (1000 x 31 + 30 x 500) = 15500 / 46000
        self::assertSame('0.336956521739130434782609', $result->rate?->toString());
    }

    #[Test]
    public function withdrawalsLargerThanTheStartValueGiveANonPositiveDenominator(): void
    {
        // 100 x 31 + 21 x (-500) = -7400.
        $result = $this->calculate('2026-02-28', '100.00', '2026-03-31', '0', [$this->flow('10', OperationType::WITHDRAWAL, '2026-03-10', '500.00')]);

        self::assertNull($result->rate);
        self::assertSame(PerformanceReason::NON_POSITIVE_DENOMINATOR, $result->reason);
    }

    #[Test]
    public function aStartValueOfZeroWithOnlyAWithdrawalIsNotAnEmptyMonth(): void
    {
        $result = $this->calculate('2026-02-28', '0', '2026-03-31', '0', [$this->flow('10', OperationType::WITHDRAWAL, '2026-03-10', '500.00')], opening: true);

        self::assertSame(PerformanceReason::NON_POSITIVE_DENOMINATOR, $result->reason);
    }

    #[Test]
    public function itCountsTwentyEightAndTwentyNineDayFebruaries(): void
    {
        // gain 10, T = 28: 10 x 28 / (1000 x 28) = 0.01 ; leap T = 29 gives the same 0.01.
        $common = $this->calculate('2027-01-31', '1000.00', '2027-02-28', '1010.00', []);
        $leap = $this->calculate('2028-01-31', '1000.00', '2028-02-29', '1010.00', [$this->flow('10', OperationType::DEPOSIT, '2028-02-15', '100.00')]);

        self::assertSame('0.010000000000000000000000', $common->rate?->toString());
        // gain = 1010 - 1000 - 100 = -90 ; T = 29 ; remaining = 14 ; denominator = 29000 + 1400
        self::assertSame('14', $leap->weights[0]['remainingDays']);
        self::assertSame('-0.085855263157894736842105', $leap->rate?->toString());
    }

    #[Test]
    public function aReturnBelowMinusOneIsReturnedAsComputed(): void
    {
        // gain = 0 - 1000 - 1000 = -2000 ; T = 31 ; denominator = 1000 x 31 + 1 x 1000 = 32000 ; R = -2000 x 31 / 32000 = -1.9375.
        $result = $this->calculate('2026-02-28', '1000.00', '2026-03-31', '0', [$this->flow('10', OperationType::DEPOSIT, '2026-03-30', '1000.00')]);

        self::assertNull($result->reason);
        self::assertSame('-1.937500000000000000000000', $result->rate?->toString());
    }

    #[Test]
    public function itFlagsAnUnvaluedSecurityTransferAsBlocking(): void
    {
        $transfer = $this->operation('12', OperationType::SECURITY_TRANSFER_IN, '2026-03-10', null, '00000000-0000-7000-8000-000000000b01', '2');

        $result = $this->calculate('2026-02-28', '1000.00', '2026-03-31', '1100.00', [$transfer]);

        self::assertNull($result->rate);
        self::assertSame([PerformanceReason::UNVALUED_SECURITY_TRANSFER], array_map(static fn ($finding) => $finding->code, $result->blocking));
    }

    #[Test]
    public function anchorFlagsBecomeWarningsAndTheRateIsStillReturned(): void
    {
        $result = $this->calculate('2026-02-28', '1000.00', '2026-03-31', '1100.00', [], endFlags: ['STALE_PRICES']);

        self::assertNotNull($result->rate);
        self::assertSame([PerformanceReason::STALE_PRICES], array_map(static fn ($finding) => $finding->code, $result->warnings));
    }

    #[Test]
    public function valuesWithTwentySixIntegerDigitsStayFinite(): void
    {
        $big = '12345678901234567890123456.000000000000000000000001';
        $result = $this->calculate('2026-02-28', $big, '2026-03-31', $big, [$this->flow('10', OperationType::DEPOSIT, '2026-03-10', $big)]);

        self::assertNull($result->reason);
        self::assertNotNull($result->rate);
    }

    #[Test]
    public function aZeroGainIsAKnownZero(): void
    {
        $result = $this->calculate('2026-02-28', '1000.00', '2026-03-31', '1000.00', []);

        self::assertSame('0.000000000000000000000000', $result->rate?->toString());
    }

    /**
     * @param list<PortfolioOperation> $operations
     * @param list<string>             $endFlags
     */
    private function calculate(string $startDate, string $start, string $endDate, string $end, array $operations, bool $opening = false, array $endFlags = []): ModifiedDietz
    {
        $startAnchor = $opening ? PeriodAnchor::opening(new \DateTimeImmutable($startDate)) : $this->anchor($startDate, $start, []);

        return (new ModifiedDietzCalculator())->calculate($startAnchor, $this->anchor($endDate, $end, $endFlags), $operations, 2);
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
