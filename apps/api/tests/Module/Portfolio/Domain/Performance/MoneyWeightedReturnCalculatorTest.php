<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Domain\Performance;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Domain\OperationDetails;
use App\Module\Portfolio\Domain\OperationState;
use App\Module\Portfolio\Domain\OperationType;
use App\Module\Portfolio\Domain\Performance\MoneyWeightedReturnCalculator;
use App\Module\Portfolio\Domain\Performance\MoneyWeightedReturnSolver;
use App\Module\Portfolio\Domain\Performance\PeriodAnchor;
use App\Module\Portfolio\Domain\PortfolioOperation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MoneyWeightedReturnCalculatorTest extends TestCase
{
    private const string ACCOUNT = '00000000-0000-7000-8000-0000000000d1';

    #[Test]
    public function investorSignsNetSameDayAndIgnoreRetainedIncome(): void
    {
        $result = (new MoneyWeightedReturnCalculator(new MoneyWeightedReturnSolver()))->calculate(
            $this->anchor('2025-12-31', '10000', []), $this->anchor('2026-12-31', '11800', []),
            [$this->flow('10', OperationType::DEPOSIT, '2026-07-01', '1500'),
                $this->flow('11', OperationType::WITHDRAWAL, '2026-07-01', '500'),
                $this->flow('12', OperationType::INTEREST, '2026-08-01', '100')],
        );
        self::assertSame('0.076247', $result['result']->annualizedRate);
        self::assertSame(3, $result['cashFlowCount']);
        self::assertSame(['-10000', '-1000', '11800'], array_column($result['cashFlows'], 'amount'));
    }

    #[Test]
    public function zeroOpeningAndZeroNetDaysAreOmitted(): void
    {
        $result = (new MoneyWeightedReturnCalculator(new MoneyWeightedReturnSolver()))->calculate(
            PeriodAnchor::opening(new \DateTimeImmutable('2026-01-01')), $this->anchor('2026-12-31', '1100', []),
            [$this->flow('10', OperationType::DEPOSIT, '2026-01-02', '1000'),
                $this->flow('11', OperationType::DEPOSIT, '2026-07-01', '500'),
                $this->flow('12', OperationType::WITHDRAWAL, '2026-07-01', '500')],
        );
        self::assertSame(2, $result['cashFlowCount']);
        self::assertSame('2026-01-02', $result['cashFlows'][0]['date']);
    }

    #[Test]
    public function responseIsCappedButCountsEveryDayBeforeRefusingDenseHistory(): void
    {
        $start = new \DateTimeImmutable('2016-01-01');
        $operations = [];
        for ($i = 1; $i <= 499; ++$i) {
            $operations[] = $this->flow('10', OperationType::DEPOSIT, $start->modify('+'.$i.' days')->format('Y-m-d'), '1');
        }
        $result = (new MoneyWeightedReturnCalculator(new MoneyWeightedReturnSolver()))->calculate(
            $this->anchor('2016-01-01', '1000', []), $this->anchor('2026-01-01', '2000', []), $operations,
        );
        self::assertSame(501, $result['cashFlowCount']);
        self::assertCount(100, $result['cashFlows']);
        self::assertSame(\App\Module\Portfolio\Domain\Performance\PerformanceReason::TOO_MANY_FLOWS, $result['result']->reason);
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
