<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application;

use App\Module\Accounts\Application\FirstNetWorthDataDayReader;
use App\Module\Accounts\Application\NetWorthHistoryView;
use App\Module\Accounts\Application\NetWorthPointView;
use App\Module\Accounts\Application\ReadNetWorthHistory;
use App\Module\Accounts\Application\ResolveNetWorthContributions;
use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountBalanceSnapshot;
use App\Module\Accounts\Domain\AccountBalanceSnapshotRepository;
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
use App\Tests\Module\Reference\Application\Double\InMemoryAssetCatalog;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class NetWorthHistoryPerformanceTest extends TestCase
{
    private const string ACCOUNT = '00000000-0000-7000-8000-0000000000d1';

    public function testEachPeriodUsesItsCalendarBaselineRatherThanTheFirstPlottedPoint(): void
    {
        $read = $this->read([
            '2020-05-15' => '500.00',
            '2025-09-05' => '800.00',
            '2025-10-31' => '900.00',
            '2025-12-31' => '1000.00',
            '2026-01-31' => '1100.00',
            '2026-09-05' => '1200.00',
        ], '2020-05-15');
        foreach ([
            'ALL' => ['2020-05-31', '700.00', '140.00'],
            'LAST_12_MONTHS' => ['2025-09-05', '400.00', '50.00'],
            'YTD' => ['2025-12-31', '200.00', '20.00'],
        ] as $period => [$day, $amount, $percent]) {
            $history = $read(period: $period);
            self::assertSame($day, $history->delta->comparedOn);
            self::assertSame($amount, $history->delta->amount?->amount);
            self::assertSame($percent, $history->delta->ratePercentDisplay);
            self::assertSame([self::ACCOUNT], $history->delta->previousSourceAccountIds);
            self::assertSame([self::ACCOUNT], $history->delta->currentSourceAccountIds);
        }
        $all = $read(period: 'ALL');
        self::assertTrue($all->truncated);
        self::assertCount(60, $all->points);
        self::assertSame('2021-10-31', $all->points[0]->on);
        self::assertSame('2020-05', $all->firstDataMonth);
        self::assertEquals($read(period: 'LAST_12_MONTHS')->delta, $read()->delta);
    }

    public function testRollingYearAndLegacyMonthsClampTheDayToTheTargetMonth(): void
    {
        $read = $this->read(['2023-02-28' => '800.00', '2024-02-29' => '1200.00'], '2023-02-28');
        $leap = $read(asOf: '2024-02-29', period: 'LAST_12_MONTHS');
        self::assertSame('2023-02-28', $leap->delta->comparedOn);
        self::assertSame('400.00', $leap->delta->amount?->amount);
        $legacy = $read(asOf: '2024-03-31', months: 1);
        self::assertSame('2024-02-29', $legacy->delta->comparedOn);
        self::assertCount(1, $legacy->points);
    }

    public function testSameDayInceptionHasZeroChangeFromItsKnownBase(): void
    {
        $history = ($this->read(['2026-01-01' => '1000.00'], '2026-01-01'))(asOf: '2026-01-01', period: 'ALL');
        self::assertCount(1, $history->points);
        self::assertSame('2026-01-01', $history->delta->comparedOn);
        self::assertSame('0.00', $history->delta->amount?->amount);
        self::assertSame('0.00', $history->delta->ratePercentDisplay);
    }

    public function testWorkedExampleDrawsTheComparisonDayFirstAndTheChangeEqualsLastMinusFirst(): void
    {
        $read = $this->read([
            '2020-05-15' => '500.00', '2025-09-05' => '800.00', '2025-12-31' => '1000.00',
            '2026-01-01' => '850.00', '2026-03-03' => '1200.00',
        ], '2020-05-15');
        $totals = static fn (NetWorthHistoryView $history): array => array_map(static fn (NetWorthPointView $p): array => [$p->on, $p->total?->amount], $history->points);

        $ytd = $read(period: 'YTD');
        self::assertSame([
            ['2025-12-31', '1000.00'], ['2026-01-31', '850.00'], ['2026-02-28', '850.00'],
            ['2026-03-31', '1200.00'], ['2026-04-30', '1200.00'], ['2026-05-31', '1200.00'],
            ['2026-06-30', '1200.00'], ['2026-07-31', '1200.00'], ['2026-08-31', '1200.00'], ['2026-09-05', '1200.00'],
        ], $totals($ytd));
        self::assertSame('2025-12-31', $ytd->delta->comparedOn);
        self::assertSame('200.00', $ytd->delta->amount?->amount);

        $rolling = $read(period: 'LAST_12_MONTHS');
        self::assertCount(13, $rolling->points);
        self::assertSame(['2025-09-05', '800.00'], $totals($rolling)[0]);
        self::assertSame('2025-09-05', $rolling->delta->comparedOn);
        self::assertSame('400.00', $rolling->delta->amount?->amount);
        self::assertSame($rolling->delta->comparedOn, $rolling->points[0]->on);
        self::assertEquals($read(months: 12)->points, array_slice($rolling->points, 1));

        $all = $read(period: 'ALL');
        self::assertTrue($all->truncated);
        self::assertSame('2020-05-31', $all->delta->comparedOn);
        self::assertSame('700.00', $all->delta->amount?->amount);
    }

    public function testTheChangeEqualsTheLastPointMinusTheFirstPointAndLegacyMonthsKeepTheirBaseline(): void
    {
        $read = $this->read(['2025-09-05' => '800.00', '2025-12-31' => '1000.00', '2026-03-03' => '1200.00'], '2025-09-05');
        foreach (['YTD' => ['1000.00', '200.00'], 'LAST_12_MONTHS' => ['800.00', '400.00'], 'ALL' => ['800.00', '400.00']] as $period => [$first, $change]) {
            $history = $read(period: $period);
            self::assertFalse($history->truncated);
            self::assertSame($first, $history->points[0]->total?->amount);
            self::assertSame('1200.00', $history->points[count($history->points) - 1]->total?->amount);
            self::assertSame($history->delta->comparedOn, $history->points[0]->on);
            self::assertSame($change, $history->delta->amount?->amount);
        }
        self::assertSame('2025-09-05', $read(months: 12)->delta->comparedOn);
        self::assertSame('2025-12-05', $read(months: 9)->delta->comparedOn);
    }

    public function testJanuaryFirstAndDecemberThirtyFirstAsOf(): void
    {
        $read = $this->read(['2025-12-31' => '1000.00', '2026-01-01' => '850.00'], '2025-12-31');
        $january = $read(asOf: '2026-01-01', period: 'YTD');
        self::assertSame(['2025-12-31', '2026-01-01'], array_map(static fn ($p): string => $p->on, $january->points));
        self::assertSame('-150.00', $january->delta->amount?->amount);
        self::assertSame('-15.00', $january->delta->ratePercentDisplay);

        $december = $read(asOf: '2026-12-31', period: 'YTD');
        self::assertCount(13, $december->points);
        self::assertSame('2026-12-31', $december->points[12]->on);
        self::assertSame('2025-12-31', $december->points[0]->on);
    }

    public function testAllComparesOnTheFirstMonthEndOrOnAsOfWithinThatMonth(): void
    {
        $read = $this->read(['2026-09-01' => '1000.00', '2026-09-05' => '1200.00'], '2026-09-01');
        $history = $read(period: 'ALL');
        self::assertSame('2026-09-05', $history->delta->comparedOn);
        self::assertCount(1, $history->points);
    }

    public function testANonCalculableDecemberBaselineKeepsTheChangeNonCalculable(): void
    {
        $history = ($this->read(['2026-03-03' => '1200.00'], '2026-03-03'))(period: 'YTD');
        self::assertSame('2025-12-31', $history->delta->comparedOn);
        self::assertSame('MISSING_VALUATION', $history->delta->previousReason);
        self::assertNull($history->delta->amount);
        self::assertNull($history->points[0]->total);
    }

    public function testMissingInceptionValueIsNotReplacedByTheFirstComputableValue(): void
    {
        $read = $this->read(['2026-08-15' => '1000.00', '2026-09-05' => '1200.00'], '2026-07-15');
        $history = $read(period: 'ALL');
        self::assertSame('2026-07-31', $history->delta->comparedOn);
        self::assertSame('MISSING_VALUATION', $history->delta->previousReason);
        self::assertNull($history->delta->amount);
        self::assertNull($history->delta->ratePercentDisplay);
        self::assertSame('MISSING_VALUATION', $history->delta->amountReason);
        self::assertSame('MISSING_VALUATION', $history->delta->rateReason);
    }

    public function testEmptyWorkspaceHasNoInventedBaseline(): void
    {
        $history = ($this->read([], null))(period: 'ALL');
        self::assertNull($history->delta->previousTotal);
        self::assertNull($history->delta->amount);
        self::assertNull($history->delta->ratePercentDisplay);
        self::assertSame('2026-09-05', $history->delta->comparedOn);
        self::assertSame('NO_ELIGIBLE_ACCOUNT', $history->delta->previousReason);
    }

    public function testZeroAndNegativeBasesKeepTheAmountAndExplainTheMissingRate(): void
    {
        foreach (['0.00' => ['1200.00', 'ZERO_BASE'], '-500.00' => ['1700.00', 'NEGATIVE_BASE']] as $base => [$amount, $reason]) {
            $history = ($this->read(['2026-07-15' => $base, '2026-09-05' => '1200.00'], '2026-07-15'))(period: 'ALL');
            self::assertSame($amount, $history->delta->amount?->amount);
            self::assertNull($history->delta->ratePercentDisplay);
            self::assertSame($reason, $history->delta->rateReason);
        }
    }

    public function testTruncatedHistoryResolvesSixtyPointsAndAnIndependentBaselineInBoundedBatches(): void
    {
        $snapshots = $this->createMock(AccountBalanceSnapshotRepository::class);
        $seen = [];
        $snapshots->expects(self::exactly(2))->method('findLatestForAccountsOnDates')->willReturnCallback(
            /**
             * @param list<string>             $accounts
             * @param list<\DateTimeImmutable> $dates
             */
            static function (WorkspaceScope $workspace, array $accounts, array $dates) use (&$seen): array {
                self::assertLessThanOrEqual(60, count($dates));
                $days = [];
                foreach ($dates as $date) {
                    self::assertInstanceOf(\DateTimeImmutable::class, $date);
                    $days[] = $date->format('Y-m-d');
                }
                $seen = [...$seen, ...$days];

                return [];
            },
        );
        ($this->read(['2026-09-05' => '1200.00'], '2020-05-15', $snapshots))(period: 'ALL');
        self::assertCount(61, array_unique($seen));
        self::assertContains('2020-05-31', $seen);
    }

    /** @param array<string, string> $amounts */
    private function read(array $amounts, ?string $firstDay, ?AccountBalanceSnapshotRepository $repository = null): ReadNetWorthHistory
    {
        $first = $this->createStub(FirstNetWorthDataDayReader::class);
        $first->method('onOrBefore')->willReturn(null === $firstDay ? null : new \DateTimeImmutable($firstDay));
        $workspace = WorkspaceFixture::own();
        $now = new \DateTimeImmutable('2026-09-05T09:00:00+00:00');
        $account = new Account(
            id: self::ACCOUNT, workspace: $workspace, label: 'Compte', assetCode: AssetCode::fromString('EUR'),
            kind: AccountKind::CURRENT, productCode: null, productModelId: null, institution: null,
            maskedIdentifier: null, valuationMode: AccountValuationMode::SNAPSHOTS, liquidityLevel: LiquidityLevel::IMMEDIATE,
            includeInNetWorth: true, includeInEmergencyFund: false, openedOn: new \DateTimeImmutable('2000-01-01'),
            closedOn: null, version: 1, createdAt: $now, updatedAt: $now,
        );
        $snapshots = [];
        foreach ($amounts as $day => $amount) {
            $snapshots[] = new AccountBalanceSnapshot(
                id: substr(sha1($day), 0, 8).'-0000-7000-8000-000000000000', workspace: $workspace,
                accountId: self::ACCOUNT, asOf: new \DateTimeImmutable($day),
                amount: new AssetAmount(DecimalValue::fromString($amount), AssetCode::fromString('EUR')),
                source: BalanceSnapshotSource::MANUAL, reconciliationStatus: ReconciliationStatus::UNRECONCILED,
                comment: null, version: 1, recordedAt: $now, recordedBy: WorkspaceFixture::OWNER_ID,
            );
        }

        return new ReadNetWorthHistory(
            new FixedCallerWorkspace(WorkspaceFixture::OWN_WORKSPACE),
            new ResolveNetWorthContributions(new InMemoryAccountRepository(...([] === $amounts ? [] : [$account])), new \App\Module\Accounts\Application\ResolveAccountValuations($repository ?? new InMemoryAccountBalanceSnapshotRepository(...$snapshots), new Double\EmptyBookedMovementTotals()), new FixedWorkspaceTimezoneReader()),
            InMemoryAssetCatalog::withCodes('EUR'), new MockClock($now), $first,
        );
    }
}
