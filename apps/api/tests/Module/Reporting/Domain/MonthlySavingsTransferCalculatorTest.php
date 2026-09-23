<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\Domain;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Reporting\Domain\MonthlyMetric;
use App\Module\Reporting\Domain\MonthlyProjectionReason;
use App\Module\Reporting\Domain\MonthlySavingsTransfer;
use App\Module\Reporting\Domain\MonthlySavingsTransferCalculator;
use PHPUnit\Framework\TestCase;

final class MonthlySavingsTransferCalculatorTest extends TestCase
{
    public function testItCountsEachCrossBoundaryPairOnceAndKeepsInternalSavingsNeutral(): void
    {
        $metrics = MonthlySavingsTransferCalculator::compute(
            ['EUR'],
            ['current' => 'CURRENT', 'savings' => 'SAVINGS', 'portfolio' => 'PORTFOLIO', 'cash' => 'CASH'],
            [
                self::pair('inflow', 'current', 'savings', '-300.00', '300.00'),
                self::pair('withdrawal', 'savings', 'current', '-50.00', '50.00'),
                self::pair('internal', 'savings', 'portfolio', '-100.00', '100.00'),
                self::pair('outside', 'current', 'cash', '-80.00', '80.00'),
            ],
            MonthlyMetric::value(DecimalValue::fromString('1000.00'), AssetCode::fromString('EUR'), ['income']),
        );

        self::assertSame('300.00', $metrics->savingsInflows->value?->toString());
        self::assertSame('50.00', $metrics->savingsWithdrawals->value?->toString());
        self::assertSame('250.00', $metrics->netSavingsTransfers->value?->toString());
        self::assertSame('0.250000000000000000000000', $metrics->netSavingsRate->value?->toString());
        self::assertSame(['inflow', 'withdrawal'], $metrics->netSavingsTransfers->sourceTransferIds);
        self::assertSame(['inflow-source', 'inflow-target'], $metrics->savingsInflows->sourceTransactionIds);
        self::assertSame(['income', 'inflow-source', 'inflow-target', 'withdrawal-source', 'withdrawal-target'], $metrics->netSavingsRate->sourceTransactionIds);
    }

    public function testNetSavingsMayBeNegativeAndExactlyZeroStaysCalculable(): void
    {
        $negative = MonthlySavingsTransferCalculator::compute(
            ['EUR'],
            ['current' => 'CURRENT', 'savings' => 'SAVINGS'],
            [
                self::pair('inflow', 'current', 'savings', '-100.00', '100.00'),
                self::pair('withdrawal', 'savings', 'current', '-300.00', '300.00'),
            ],
            MonthlyMetric::value(DecimalValue::fromString('400.00'), AssetCode::fromString('EUR')),
        );
        self::assertSame('-200.00', $negative->netSavingsTransfers->value?->toString());
        self::assertSame('-0.500000000000000000000000', $negative->netSavingsRate->value?->toString());

        $zero = MonthlySavingsTransferCalculator::compute(
            ['EUR'],
            ['current' => 'CURRENT', 'savings' => 'SAVINGS'],
            [
                self::pair('inflow', 'current', 'savings', '-100.00', '100.00'),
                self::pair('withdrawal', 'savings', 'current', '-100.00', '100.00'),
            ],
            MonthlyMetric::value(DecimalValue::fromString('400.00'), AssetCode::fromString('EUR')),
        );
        self::assertSame('0.00', $zero->netSavingsTransfers->value?->toString());
        self::assertNull($zero->netSavingsTransfers->reason);
    }

    public function testPendingAndVoidedPairsDoNotEnterSumsAndPendingIsCountedOnce(): void
    {
        $metrics = MonthlySavingsTransferCalculator::compute(
            ['EUR'],
            ['current' => 'CURRENT', 'savings' => 'SAVINGS'],
            [
                self::pair('booked', 'current', 'savings', '-20.00', '20.00'),
                self::pair('pending', 'current', 'savings', '-30.00', '30.00', state: 'PENDING'),
                self::pair('voided', 'current', 'savings', '-40.00', '40.00', state: 'VOIDED', voided: true),
            ],
            MonthlyMetric::value(DecimalValue::fromString('100.00'), AssetCode::fromString('EUR')),
        );

        self::assertSame('20.00', $metrics->netSavingsTransfers->value?->toString());
        self::assertSame(1, $metrics->savingsInflows->pendingCount);
        self::assertSame(1, $metrics->netSavingsTransfers->pendingCount);
        self::assertSame(['booked'], $metrics->netSavingsTransfers->sourceTransferIds);
    }

    public function testZeroIncomeAndInvalidPairsHaveStableNonCalculableReasons(): void
    {
        $zeroIncome = MonthlySavingsTransferCalculator::compute(
            ['EUR'],
            ['current' => 'CURRENT', 'savings' => 'SAVINGS'],
            [self::pair('inflow', 'current', 'savings', '-10.00', '10.00')],
            MonthlyMetric::value(DecimalValue::zero(), AssetCode::fromString('EUR')),
        );
        self::assertSame('10.00', $zeroIncome->netSavingsTransfers->value?->toString());
        self::assertSame(MonthlyProjectionReason::ZERO_CASH_INCOME, $zeroIncome->netSavingsRate->reason);

        $missingLeg = MonthlySavingsTransferCalculator::compute(
            ['EUR'],
            ['current' => 'CURRENT', 'savings' => 'SAVINGS'],
            [self::pair('broken', 'current', null, '-10.00', null)],
            MonthlyMetric::value(DecimalValue::fromString('100.00'), AssetCode::fromString('EUR')),
        );
        self::assertSame(MonthlyProjectionReason::INCOMPLETE_TRANSFER_PAIR, $missingLeg->netSavingsTransfers->reason);

        $mixedState = MonthlySavingsTransferCalculator::compute(
            ['EUR'],
            ['current' => 'CURRENT', 'savings' => 'SAVINGS'],
            [self::pair('mixed-state', 'current', 'savings', '-10.00', '10.00', targetState: 'PENDING')],
            MonthlyMetric::value(DecimalValue::fromString('100.00'), AssetCode::fromString('EUR')),
        );
        self::assertSame(MonthlyProjectionReason::MISMATCHED_TRANSFER_PAIR, $mixedState->netSavingsTransfers->reason);

        $mixedDay = MonthlySavingsTransferCalculator::compute(
            ['EUR'],
            ['current' => 'CURRENT', 'savings' => 'SAVINGS'],
            [self::pair('mixed-day', 'current', 'savings', '-10.00', '10.00', targetBookedOn: '2026-09-16')],
            MonthlyMetric::value(DecimalValue::fromString('100.00'), AssetCode::fromString('EUR')),
        );
        self::assertSame(MonthlyProjectionReason::MISMATCHED_TRANSFER_PAIR, $mixedDay->netSavingsTransfers->reason);

        $missingClassification = MonthlySavingsTransferCalculator::compute(
            ['EUR'],
            ['current' => 'CURRENT'],
            [self::pair('unclassified', 'current', 'missing', '-10.00', '10.00')],
            MonthlyMetric::value(DecimalValue::fromString('100.00'), AssetCode::fromString('EUR')),
        );
        self::assertSame(MonthlyProjectionReason::MISSING_ACCOUNT_CLASSIFICATION, $missingClassification->netSavingsTransfers->reason);
    }

    public function testMixedPairAssetsMakeTheSavingsMetricsNonCalculable(): void
    {
        $pair = self::pair('mixed', 'current', 'savings', '-10.00', '11.00');
        $pair = new MonthlySavingsTransfer(
            $pair->transferId,
            $pair->sourceTransactionId,
            $pair->targetTransactionId,
            $pair->sourceAccountId,
            $pair->targetAccountId,
            $pair->sourceAmount,
            $pair->targetAmount,
            AssetCode::fromString('EUR'),
            AssetCode::fromString('USD'),
            $pair->sourceState,
            $pair->targetState,
            $pair->sourceBookedOn,
            $pair->targetBookedOn,
            false,
        );

        $metrics = MonthlySavingsTransferCalculator::compute(
            ['EUR'],
            ['current' => 'CURRENT', 'savings' => 'SAVINGS'],
            [$pair],
            MonthlyMetric::value(DecimalValue::fromString('100.00'), AssetCode::fromString('EUR')),
        );

        self::assertSame(MonthlyProjectionReason::MIXED_ASSETS, $metrics->netSavingsTransfers->reason);
    }

    private static function pair(
        string $id,
        ?string $sourceAccount,
        ?string $targetAccount,
        ?string $sourceAmount,
        ?string $targetAmount,
        string $state = 'BOOKED',
        ?string $targetState = null,
        bool $voided = false,
        string $targetBookedOn = '2026-09-15',
    ): MonthlySavingsTransfer {
        return new MonthlySavingsTransfer(
            $id,
            $id.'-source',
            null === $targetAccount ? null : $id.'-target',
            $sourceAccount,
            $targetAccount,
            null === $sourceAmount ? null : DecimalValue::fromString($sourceAmount),
            null === $targetAmount ? null : DecimalValue::fromString($targetAmount),
            null === $sourceAmount ? null : AssetCode::fromString('EUR'),
            null === $targetAmount ? null : AssetCode::fromString('EUR'),
            $state,
            $targetState ?? $state,
            '2026-09-15',
            null === $targetAccount ? null : $targetBookedOn,
            $voided,
        );
    }
}
