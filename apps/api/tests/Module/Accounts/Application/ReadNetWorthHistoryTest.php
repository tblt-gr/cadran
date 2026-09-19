<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application;

use App\Module\Accounts\Application\InvalidNetWorthQuery;
use App\Module\Accounts\Application\ReadNetWorthHistory;
use App\Module\Accounts\Application\ResolveNetWorthContributions;
use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountBalanceSnapshot;
use App\Module\Accounts\Domain\AccountValuationMode;
use App\Module\Accounts\Domain\BalanceSnapshotSource;
use App\Module\Accounts\Domain\LiquidityLevel;
use App\Module\Accounts\Domain\ReconciliationStatus;
use App\Module\Catalog\Domain\AccountKind;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Tests\Module\Accounts\Application\Double\FixedCallerWorkspace;
use App\Tests\Module\Accounts\Application\Double\FixedWorkspaceTimezoneReader;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountBalanceSnapshotRepository;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountRepository;
use App\Tests\Module\Accounts\Domain\AccountGroupFixture;
use App\Tests\Module\Reference\Application\Double\InMemoryAssetCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The curve is recomputed day by day from the valuations that were valid on
 * each day, so a late entry lands on the day it describes.
 */
final class ReadNetWorthHistoryTest extends TestCase
{
    private const string WORKSPACE = AccountGroupFixture::WORKSPACE;
    private const string CASH = '00000000-0000-7000-8000-0000000000d1';

    public function testTheCurveEndsOnTheRequestedDayAndCarriesMonthEndsBefore(): void
    {
        $history = ($this->read())(months: 3);

        self::assertSame('MONTH', $history->granularity);
        self::assertSame(
            ['2026-07-31', '2026-08-31', '2026-09-05'],
            array_map(static fn ($point): string => $point->on, $history->points),
        );
        self::assertSame('10000.00', $history->points[0]->total?->amount);
        self::assertSame('12000.00', $history->points[1]->total?->amount);
        self::assertSame('12500.00', $history->points[2]->total?->amount);
    }

    public function testAMonthWithoutAValuationKeepsItsPlaceWithAReason(): void
    {
        $history = ($this->read())(months: 12);

        self::assertCount(12, $history->points);

        // 2025-10-31, before the account was opened: nothing was eligible yet.
        self::assertSame('2025-10-31', $history->points[0]->on);
        self::assertNull($history->points[0]->total);
        self::assertSame('NO_ELIGIBLE_ACCOUNT', $history->points[0]->reason);

        // 2026-01-31, the account exists but carries no valuation yet.
        self::assertSame('2026-01-31', $history->points[3]->on);
        self::assertNull($history->points[3]->total);
        self::assertSame('MISSING_VALUATION', $history->points[3]->reason);
        self::assertSame('MISSING', $history->points[3]->quality);
    }

    public function testACarriedForwardMonthIsMarkedStale(): void
    {
        $history = ($this->read())(months: 2);

        self::assertSame('12000.00', $history->points[0]->total?->amount);
        self::assertSame('STALE', $history->points[0]->quality);
    }

    public function testASpanOutsideItsBoundsIsRefused(): void
    {
        $this->expectException(InvalidNetWorthQuery::class);

        ($this->read())(months: ReadNetWorthHistory::MAX_MONTHS + 1);
    }

    public function testAZeroSpanIsRefused(): void
    {
        $this->expectException(InvalidNetWorthQuery::class);

        ($this->read())(months: 0);
    }

    private function read(): ReadNetWorthHistory
    {
        return new ReadNetWorthHistory(
            new FixedCallerWorkspace(self::WORKSPACE),
            new ResolveNetWorthContributions(
                new InMemoryAccountRepository($this->account()),
                new InMemoryAccountBalanceSnapshotRepository(
                    $this->snapshot('2026-07-20', '10000.00'),
                    $this->snapshot('2026-08-15', '12000.00'),
                    $this->snapshot('2026-09-05', '12500.00'),
                ),
                new FixedWorkspaceTimezoneReader(),
            ),
            InMemoryAssetCatalog::withCodes('EUR'),
            new MockClock('2026-09-05 09:00:00', 'UTC'),
        );
    }

    private function account(): Account
    {
        $now = new \DateTimeImmutable('2026-01-10T10:00:00+00:00');

        return new Account(
            id: self::CASH,
            workspace: WorkspaceScope::fromString(self::WORKSPACE),
            label: 'Compte courant',
            assetCode: AssetCode::fromString('EUR'),
            kind: AccountKind::CURRENT,
            productCode: null,
            productModelId: null,
            institution: null,
            maskedIdentifier: null,
            valuationMode: AccountValuationMode::TRANSACTIONS,
            liquidityLevel: LiquidityLevel::IMMEDIATE,
            includeInNetWorth: true,
            includeInEmergencyFund: false,
            openedOn: new \DateTimeImmutable('2026-01-10', new \DateTimeZone('UTC')),
            closedOn: null,
            version: 1,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    private function snapshot(string $asOf, string $amount): AccountBalanceSnapshot
    {
        return new AccountBalanceSnapshot(
            id: '00000000-0000-7000-8000-0000000000'.substr(str_replace('-', '', $asOf), -2),
            workspace: WorkspaceScope::fromString(self::WORKSPACE),
            accountId: self::CASH,
            asOf: new \DateTimeImmutable($asOf, new \DateTimeZone('UTC')),
            amount: new AssetAmount(DecimalValue::fromString($amount), AssetCode::fromString('EUR')),
            source: BalanceSnapshotSource::MANUAL,
            reconciliationStatus: ReconciliationStatus::UNRECONCILED,
            comment: null,
            version: 1,
            recordedAt: new \DateTimeImmutable($asOf.'T10:00:00+00:00'),
            recordedBy: '00000000-0000-7000-8000-000000000001',
        );
    }
}
