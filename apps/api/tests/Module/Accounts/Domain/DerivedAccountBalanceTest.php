<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Domain;

use App\Module\Accounts\Domain\AccountBalanceSnapshot;
use App\Module\Accounts\Domain\AccountBalanceSnapshots;
use App\Module\Accounts\Domain\AccountValuation;
use App\Module\Accounts\Domain\AccountValuationMode;
use App\Module\Accounts\Domain\BalanceSnapshotSource;
use App\Module\Accounts\Domain\MovementTotal;
use App\Module\Accounts\Domain\MovementWindow;
use App\Module\Accounts\Domain\ReconciliationStatus;
use App\Module\Accounts\Domain\ValuationBasis;
use App\Module\Accounts\Domain\ValuationQuality;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use PHPUnit\Framework\TestCase;

final class DerivedAccountBalanceTest extends TestCase
{
    public function testAnchorOnlyKeepsCurrentOrStaleAndLaterSnapshotResetsTheBalance(): void
    {
        $anchor = $this->anchor('2026-09-30', '1250.40');
        $empty = new MovementTotal(null, 0, 2, false);
        self::assertSame(ValuationQuality::CURRENT, AccountValuation::derived($anchor, $this->day('2026-09-30'), $empty)->quality);
        $stale = AccountValuation::derived($anchor, $this->day('2026-10-10'), $empty);
        self::assertSame(ValuationQuality::STALE, $stale->quality);
        self::assertSame(10, $stale->ageDays);
        self::assertSame(ValuationBasis::SNAPSHOT, $stale->basis);
        self::assertNull($stale->derivation);
        $later = AccountValuation::derived($this->anchor('2026-10-20', '2650.00'), $this->day('2026-10-31'), new MovementTotal(null, 0, 0, false));
        self::assertSame('2650.00', $later->amount?->value->toString());
    }

    public function testZeroNegativeAndTwentyFourDecimalsAreExact(): void
    {
        $anchor = $this->anchor('2026-09-30', '0.000000000000000000000001');
        $positive = AccountValuation::derived($anchor, $this->day('2026-10-10'), new MovementTotal(DecimalValue::fromString('0.000000000000000000000002'), 1, 0, false));
        self::assertSame('0.000000000000000000000003', $positive->amount?->value->toString());
        $zero = AccountValuation::derived($anchor, $this->day('2026-10-10'), new MovementTotal(DecimalValue::fromString('-0.000000000000000000000001'), 1, 0, false));
        self::assertSame('0.000000000000000000000000', $zero->amount?->value->toString());
        self::assertSame(ValuationQuality::CURRENT, $zero->quality);
        $negative = AccountValuation::derived($this->anchor('2026-09-30', '10.00'), $this->day('2026-10-10'), new MovementTotal(DecimalValue::fromString('-15.00'), 1, 0, false));
        self::assertSame('-5.00', $negative->amount?->value->toString());
    }

    public function testForeignAssetIsMissingEvenWhenNoMatchingBookedMovementExists(): void
    {
        $valuation = AccountValuation::derived($this->anchor('2026-09-30', '10.00'), $this->day('2026-10-10'), new MovementTotal(null, 0, 0, true));
        self::assertNull($valuation->amount);
        self::assertSame(ValuationQuality::MISSING, $valuation->quality);
        self::assertSame('MIXED_ASSETS', $valuation->reason);
        self::assertNull($valuation->basis);
        self::assertNull($valuation->derivation);
    }

    public function testCalculatedSnapshotBasisDependsOnAccountMode(): void
    {
        $snapshot = $this->snapshot('00000000-0000-7000-8000-0000000000b1', '2026-09-30', '10.00', BalanceSnapshotSource::CALCULATED, '2026-09-30T10:00:00+00:00');
        self::assertSame(ValuationBasis::SNAPSHOT, AccountValuation::of(new AccountBalanceSnapshots([$snapshot]), $this->day('2026-10-10'), AccountValuationMode::SNAPSHOTS)->basis);
        self::assertSame(ValuationBasis::PORTFOLIO_VALUATION, AccountValuation::of(new AccountBalanceSnapshots([$snapshot]), $this->day('2026-10-10'), AccountValuationMode::PORTFOLIO)->basis);
    }

    public function testMovementWindowExcludesAnchorAndRefusesAnEmptyInterval(): void
    {
        $window = new MovementWindow(AccountFixture::ID, $this->day('2026-09-30'), $this->day('2026-10-10'), AssetCode::fromString('EUR'));
        self::assertSame(AccountFixture::ID.'|2026-09-30|2026-10-10', $window->key());
        $this->expectException(\InvalidArgumentException::class);
        new MovementWindow(AccountFixture::ID, $this->day('2026-09-30'), $this->day('2026-09-30'), AssetCode::fromString('EUR'));
    }

    private function anchor(string $day, string $amount): AccountBalanceSnapshot
    {
        return $this->snapshot('00000000-0000-7000-8000-0000000000b1', $day, $amount, BalanceSnapshotSource::MANUAL, $day.'T10:00:00+00:00');
    }

    private function snapshot(
        string $id,
        string $asOf,
        string $amount,
        BalanceSnapshotSource $source,
        string $recordedAt,
    ): AccountBalanceSnapshot {
        return new AccountBalanceSnapshot(
            id: $id,
            workspace: WorkspaceScope::fromString(AccountFixture::WORKSPACE),
            accountId: AccountFixture::ID,
            asOf: $this->day($asOf),
            amount: new AssetAmount(DecimalValue::fromString($amount), AssetCode::fromString('EUR')),
            source: $source,
            reconciliationStatus: ReconciliationStatus::UNRECONCILED,
            comment: null,
            version: 1,
            recordedAt: new \DateTimeImmutable($recordedAt),
            recordedBy: '00000000-0000-7000-8000-000000000001',
        );
    }

    private function day(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
    }
}
