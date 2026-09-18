<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Domain\Reconciliation;

use App\Module\Accounts\Domain\AccountBalanceSnapshot;
use App\Module\Accounts\Domain\BalanceSnapshotSource;
use App\Module\Accounts\Domain\ReconciliationStatus;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\Reconciliation\AccountBalanceComparison;
use App\Module\Transactions\Domain\Reconciliation\ReconciliationReason;
use App\Module\Transactions\Domain\Reconciliation\ReconciliationResolution;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * discrepancy = closing - (opening + Σ movements). Worked example: opening
 * 1000.00, booked +250.50 and -80.25 give 1170.25; an observed closing of
 * 1165.00 leaves -5.25 that no movement explains.
 */
final class AccountBalanceComparisonTest extends TestCase
{
    public function testTheWorkedExampleMatchesWhenTheClosingEqualsTheMovements(): void
    {
        $comparison = $this->compare(opening: '1000.00', closing: '1170.25', movements: ['EUR' => '170.25']);

        self::assertNull($comparison->reason);
        self::assertSame('1000.00', $comparison->opening?->toString());
        self::assertSame('170.25', $comparison->movements?->toString());
        self::assertTrue($comparison->isBalanced());
        self::assertSame('0.00', $comparison->discrepancy?->toString());
    }

    public function testAMissingCentIsANegativeDiscrepancy(): void
    {
        $comparison = $this->compare(opening: '1000.00', closing: '1165.00', movements: ['EUR' => '170.25']);

        self::assertSame('-5.25', $comparison->discrepancy?->toString());
        self::assertFalse($comparison->isBalanced());
    }

    public function testAnExcessIsAPositiveDiscrepancy(): void
    {
        $comparison = $this->compare(opening: '1000.00', closing: '1200', movements: ['EUR' => '170.25']);

        self::assertSame('29.75', $comparison->discrepancy?->toString());
    }

    public function testDecimalsAreExactNotFloats(): void
    {
        $comparison = $this->compare(opening: '0.1', closing: '0.3', movements: ['EUR' => '0.2']);

        self::assertTrue($comparison->isBalanced());
    }

    public function testVeryLargeDecimalsStayExact(): void
    {
        $comparison = $this->compare(
            opening: '12345678901234567890.000000000000000001',
            closing: '12345678901234567891.000000000000000003',
            movements: ['EUR' => '1.000000000000000001'],
        );

        self::assertSame('0.000000000000000001', $comparison->discrepancy?->toString());
    }

    public function testEquivalentScalesAreBalancedEvenThoughTheirLiteralsDiffer(): void
    {
        $comparison = $this->compare(opening: '1.10', closing: '1.1', movements: []);

        self::assertTrue($comparison->isBalanced());
        self::assertSame('0', $comparison->movements?->toString());
    }

    public function testNoMovementAtAllIsARealZeroNotAMissingFigure(): void
    {
        $comparison = $this->compare(opening: '10', closing: '12', movements: []);

        self::assertSame('0', $comparison->movements?->toString());
        self::assertSame('2', $comparison->discrepancy?->toString());
    }

    public function testAMissingOpeningBalanceMakesTheDiscrepancyNullNeverZero(): void
    {
        $comparison = $this->compare(opening: null, closing: '10', movements: ['EUR' => '10']);

        self::assertSame(ReconciliationReason::MISSING_OPENING_BALANCE, $comparison->reason);
        self::assertNull($comparison->discrepancy);
        self::assertNull($comparison->opening);
        self::assertFalse($comparison->isBalanced());
    }

    public function testASupersededOrNotLatestClosingIsNotCalculable(): void
    {
        $comparison = $this->compare(opening: '10', closing: '10', movements: [], current: false);

        self::assertSame(ReconciliationReason::STALE_CLOSING_BALANCE, $comparison->reason);
        self::assertNull($comparison->discrepancy);
    }

    public function testStaleClosingWinsOverAMissingOpening(): void
    {
        $comparison = $this->compare(opening: null, closing: '10', movements: [], current: false);

        self::assertSame(ReconciliationReason::STALE_CLOSING_BALANCE, $comparison->reason);
    }

    /** @param array<string, string> $movements */
    #[DataProvider('mixedAssets')]
    public function testSeveralAssetsCannotBeSummedIntoOneDiscrepancy(?string $openingAsset, array $movements): void
    {
        $comparison = $this->compare(opening: '10', closing: '10', movements: $movements, openingAsset: $openingAsset ?? 'EUR');

        self::assertSame(ReconciliationReason::MIXED_ASSETS, $comparison->reason);
        self::assertNull($comparison->discrepancy);
        self::assertNull($comparison->movements);
    }

    /** @return iterable<string, array{?string, array<string, string>}> */
    public static function mixedAssets(): iterable
    {
        yield 'opening in another asset' => ['USD', []];
        yield 'movement in another asset' => [null, ['EUR' => '1', 'USD' => '2']];
        yield 'only movements in another asset' => [null, ['USD' => '2']];
    }

    public function testAZeroDiscrepancyOffersOnlyTheMatch(): void
    {
        $comparison = $this->compare(opening: '1', closing: '1', movements: []);

        self::assertSame([ReconciliationResolution::MATCH], $comparison->availableResolutions());
    }

    public function testANonZeroDiscrepancyOffersOverrideAndAdjustment(): void
    {
        $comparison = $this->compare(opening: '1', closing: '2', movements: []);

        self::assertSame([ReconciliationResolution::OVERRIDE, ReconciliationResolution::ADJUST], $comparison->availableResolutions());
    }

    public function testANonCalculableComparisonOffersNothing(): void
    {
        $comparison = $this->compare(opening: null, closing: '2', movements: []);

        self::assertSame([], $comparison->availableResolutions());
    }

    /** @param array<string, string> $movements */
    private function compare(
        ?string $opening,
        string $closing,
        array $movements,
        bool $current = true,
        string $openingAsset = 'EUR',
    ): AccountBalanceComparison {
        return AccountBalanceComparison::of(
            closing: $this->snapshot('00000000-0000-7000-8000-0000000000b2', '2026-04-30', $closing, 'EUR'),
            closingIsCurrent: $current,
            opening: null === $opening ? null : $this->snapshot('00000000-0000-7000-8000-0000000000b1', '2026-03-31', $opening, $openingAsset),
            movementSums: array_map(
                static fn (string $asset, string $value): AssetAmount => new AssetAmount(DecimalValue::fromString($value), AssetCode::fromString($asset)),
                array_keys($movements),
                array_values($movements),
            ),
        );
    }

    private function snapshot(string $id, string $asOf, string $amount, string $asset): AccountBalanceSnapshot
    {
        return new AccountBalanceSnapshot(
            id: $id,
            workspace: WorkspaceScope::fromString('00000000-0000-7000-8000-0000000000a1'),
            accountId: '00000000-0000-7000-8000-0000000000d1',
            asOf: new \DateTimeImmutable($asOf, new \DateTimeZone('UTC')),
            amount: new AssetAmount(DecimalValue::fromString($amount), AssetCode::fromString($asset)),
            source: BalanceSnapshotSource::MANUAL,
            reconciliationStatus: ReconciliationStatus::UNRECONCILED,
            comment: null,
            version: 1,
            recordedAt: new \DateTimeImmutable('2026-05-01T10:00:00+00:00'),
            recordedBy: '00000000-0000-7000-8000-000000000001',
        );
    }
}
