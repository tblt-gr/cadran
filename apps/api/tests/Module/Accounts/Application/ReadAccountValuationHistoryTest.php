<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application;

use App\Module\Accounts\Application\BookedMovementTotals;
use App\Module\Accounts\Application\ReadAccountValuationHistory;
use App\Module\Accounts\Application\ResolveAccountValuations;
use App\Module\Accounts\Domain\AccountBalanceSnapshotRepository;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Tests\Module\Accounts\Application\Double\FixedCallerWorkspace;
use App\Tests\Module\Accounts\Application\Double\FixedWorkspaceTimezoneReader;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountRepository;
use App\Tests\Module\Accounts\Domain\AccountFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ReadAccountValuationHistoryTest extends TestCase
{
    public function testFullWindowHasSevenBoundedSnapshotReadsAndNoPerMovementQuery(): void
    {
        $account = AccountFixture::account();
        $caller = new FixedCallerWorkspace(AccountFixture::WORKSPACE);
        $snapshots = $this->createMock(AccountBalanceSnapshotRepository::class);
        $dayCount = 0;
        $snapshots->expects(self::exactly(7))->method('findLatestForAccountsOnDates')->willReturnCallback(
            static function (WorkspaceScope $workspace, array $accounts, array $dates) use (&$dayCount, $account): array {
                self::assertTrue($account->workspace->equals($workspace));
                self::assertSame([$account->id], $accounts);
                self::assertLessThanOrEqual(60, count($dates));
                $dayCount += count($dates);

                return [];
            },
        );
        $movements = $this->createMock(BookedMovementTotals::class);
        $movements->expects(self::never())->method('forWindows');
        $read = new ReadAccountValuationHistory($caller, new InMemoryAccountRepository($account), new ResolveAccountValuations($snapshots, $movements), new WorkspaceCalendar(new MockClock('2026-10-20', 'UTC'), $caller, new FixedWorkspaceTimezoneReader()), $snapshots);
        $history = $read($account->id, '2025-10-19', '2026-10-20');
        self::assertSame(367, $dayCount);
        self::assertCount(2, $history->points);
        self::assertFalse($history->hasHistory);
    }

    public function testEveryDailySnapshotIsPreservedUpTo367Points(): void
    {
        $account = AccountFixture::account();
        $caller = new FixedCallerWorkspace(AccountFixture::WORKSPACE);
        $snapshots = $this->createMock(AccountBalanceSnapshotRepository::class);
        $snapshots->expects(self::exactly(7))->method('findLatestForAccountsOnDates')->willReturnCallback(
            static function (WorkspaceScope $workspace, array $accounts, array $dates) use ($account): array {
                $result = [];
                foreach ($dates as $day) {
                    self::assertInstanceOf(\DateTimeImmutable::class, $day);
                    $date = $day->format('Y-m-d');
                    $result[$date][$account->id] = new \App\Module\Accounts\Domain\AccountBalanceSnapshot(
                        '00000000-0000-7000-8000-'.substr(md5($date), 0, 12),
                        $workspace,
                        $account->id,
                        $day,
                        new \App\Module\Foundation\Domain\AssetAmount(\App\Module\Foundation\Domain\DecimalValue::fromString('9999999999999999999999999.123456789012345678901234'), \App\Module\Foundation\Domain\AssetCode::fromString('EUR')),
                        \App\Module\Accounts\Domain\BalanceSnapshotSource::MANUAL,
                        \App\Module\Accounts\Domain\ReconciliationStatus::UNRECONCILED,
                        null,
                        1,
                        $day,
                        '00000000-0000-7000-8000-000000000001',
                    );
                }

                return $result;
            },
        );
        $movements = $this->createMock(BookedMovementTotals::class);
        $movements->expects(self::never())->method('forWindows');
        $read = new ReadAccountValuationHistory($caller, new InMemoryAccountRepository($account), new ResolveAccountValuations($snapshots, $movements), new WorkspaceCalendar(new MockClock('2026-10-20', 'UTC'), $caller, new FixedWorkspaceTimezoneReader()), $snapshots);
        $history = $read($account->id, '2025-10-19', '2026-10-20');
        self::assertCount(367, $history->points);
        self::assertSame('9999999999999999999999999.123456789012345678901234', $history->points[366]->amount);
        self::assertSame('2026-10-20', $history->points[366]->date);
    }

    public function testCurrentDetailAndHistoryAgreeAcrossWorkspaceMidnight(): void
    {
        $account = AccountFixture::account();
        $accounts = new InMemoryAccountRepository($account);
        $caller = new FixedCallerWorkspace(AccountFixture::WORKSPACE);
        $clock = new MockClock('2026-10-19 23:30:00', 'UTC');
        $calendar = new WorkspaceCalendar($clock, $caller, new FixedWorkspaceTimezoneReader('Europe/Paris'));
        $anchor = new \App\Module\Accounts\Domain\AccountBalanceSnapshot(
            '00000000-0000-7000-8000-0000000000b1',
            $account->workspace,
            $account->id,
            new \DateTimeImmutable('2026-10-01', new \DateTimeZone('UTC')),
            new \App\Module\Foundation\Domain\AssetAmount(\App\Module\Foundation\Domain\DecimalValue::fromString('1000'), \App\Module\Foundation\Domain\AssetCode::fromString('EUR')),
            \App\Module\Accounts\Domain\BalanceSnapshotSource::MANUAL,
            \App\Module\Accounts\Domain\ReconciliationStatus::UNRECONCILED,
            null,
            1,
            new \DateTimeImmutable('2026-10-01T10:00:00+00:00'),
            '00000000-0000-7000-8000-000000000001',
        );
        $movements = $this->createStub(BookedMovementTotals::class);
        $movements->method('forWindows')->willReturnCallback(static function (WorkspaceScope $workspace, array $windows): array {
            $totals = [];
            foreach ($windows as $window) {
                self::assertInstanceOf(\App\Module\Accounts\Domain\MovementWindow::class, $window);
                $totals[$window->key()] = $window->upToInclusive->format('Y-m-d') >= '2026-10-20'
                    ? new \App\Module\Accounts\Domain\MovementTotal(\App\Module\Foundation\Domain\DecimalValue::fromString('400'), 1, 0, false)
                    : new \App\Module\Accounts\Domain\MovementTotal(null, 0, 0, false);
            }

            return $totals;
        });
        $resolver = new ResolveAccountValuations(new Double\InMemoryAccountBalanceSnapshotRepository($anchor), $movements);
        $current = new \App\Module\Accounts\Application\ResolveAccountValuation($resolver, \App\Tests\Module\Reference\Application\Double\InMemoryAssetCatalog::withCodes('EUR'), $calendar);
        $detail = (new \App\Module\Accounts\Application\ReadAccount($caller, $accounts, $current))($account->id);
        $history = (new ReadAccountValuationHistory($caller, $accounts, $resolver, $calendar, new Double\InMemoryAccountBalanceSnapshotRepository($anchor)))($account->id, '2026-10-19', '2026-10-20');
        self::assertSame('1000', $history->points[0]->amount);
        self::assertSame('1400', $history->points[1]->amount);
        self::assertNotNull($detail->valuation);
        self::assertSame('1400', $detail->valuation->amount);
        self::assertSame('2026-10-20', $detail->valuation->requestedOn);
        self::assertSame($history->points[1]->amount, $current->current($account->workspace, $account)->amount);
        $list = new \App\Module\Accounts\Application\ListAccounts($caller, $accounts, $resolver, \App\Tests\Module\Reference\Application\Double\InMemoryAssetCatalog::withCodes('EUR'), $calendar);
        $listed = $list(false, false, null, null)->items[0]->valuation;
        self::assertNotNull($listed);
        self::assertSame('1400', $listed->amount);
        self::assertSame('2026-10-20', $listed->requestedOn);
        self::assertSame('1000', $list(false, false, null, null, asOf: '2026-10-19')->items[0]->valuation?->amount);
    }

    public function testFutureBoundUsesWorkspaceLocalToday(): void
    {
        $account = AccountFixture::account();
        $caller = new FixedCallerWorkspace(AccountFixture::WORKSPACE);
        $snapshots = $this->createMock(AccountBalanceSnapshotRepository::class);
        $snapshots->expects(self::once())->method('findLatestForAccountsOnDates')->willReturn([]);
        $read = new ReadAccountValuationHistory($caller, new InMemoryAccountRepository($account), new ResolveAccountValuations($snapshots, $this->createStub(BookedMovementTotals::class)), new WorkspaceCalendar(new MockClock('2026-10-19 23:30:00', 'UTC'), $caller, new FixedWorkspaceTimezoneReader('Europe/Paris')), $snapshots);
        self::assertSame('2026-10-20', $read($account->id, '2026-10-20', '2026-10-20')->to);
        $this->expectException(\App\Module\Accounts\Application\InvalidAccountBalanceInput::class);
        $read($account->id, '2026-10-20', '2026-10-21');
    }

    public function testOmittedFromStartsAtTheEarliestSnapshotDay(): void
    {
        $account = AccountFixture::account();
        $read = $this->readerWith($account, $this->snapshotOn($account, '2024-02-29'));
        $history = $read($account->id, null, '2026-10-20');
        self::assertSame('2024-02-29', $history->from);
        self::assertFalse($history->truncated);
        self::assertTrue($history->hasHistory);
        self::assertSame('2024-02-29', $history->points[0]->date);
        self::assertSame('1000', $history->points[0]->amount);
        self::assertSame('2026-10-20', $history->points[count($history->points) - 1]->date);
    }

    public function testOmittedFromWithoutAnyAnchorIsASingleBoundaryPoint(): void
    {
        $account = AccountFixture::account();
        $history = ($this->readerWith($account))($account->id, null, '2026-10-20');
        self::assertSame('2026-10-20', $history->from);
        self::assertFalse($history->truncated);
        self::assertFalse($history->hasHistory);
        self::assertCount(1, $history->points);
        self::assertNull($history->points[0]->amount);
    }

    public function testOmittedFromIgnoresOtherAccountSnapshots(): void
    {
        $account = AccountFixture::account();
        $other = $this->snapshotOn($account, '2020-01-01', '00000000-0000-7000-8000-0000000000c1', 'ffffffff-0000-7000-8000-000000000001');
        $history = ($this->readerWith($account, $other, $this->snapshotOn($account, '2025-05-05')))($account->id, null, '2026-10-20');
        self::assertSame('2025-05-05', $history->from);
    }

    public function testOmittedFromWithEarliestSnapshotAfterToIsASingleUntruncatedDay(): void
    {
        $account = AccountFixture::account();
        $history = ($this->readerWith($account, $this->snapshotOn($account, '2026-10-20')))($account->id, null, '2026-10-15');
        self::assertSame('2026-10-15', $history->from);
        self::assertFalse($history->truncated);
        self::assertFalse($history->hasHistory);
        self::assertCount(1, $history->points);
    }

    public function testOmittedFromOlderThan3660DaysIsCappedAndFlaggedTruncated(): void
    {
        $account = AccountFixture::account();
        $history = ($this->readerWith($account, $this->snapshotOn($account, '2010-01-01')))($account->id, null, '2026-10-20');
        self::assertSame('2016-10-12', $history->from);
        self::assertTrue($history->truncated);
        self::assertSame('1000', $history->points[0]->amount);
        self::assertSame('2016-10-12', $history->points[0]->date);
    }

    public function testOmittedFromExactly3660DaysBackIsNotTruncated(): void
    {
        $account = AccountFixture::account();
        $history = ($this->readerWith($account, $this->snapshotOn($account, '2016-10-12')))($account->id, null, '2026-10-20');
        self::assertSame('2016-10-12', $history->from);
        self::assertFalse($history->truncated);
    }

    public function testExplicitFromBeyond366ElapsedDaysIsStillRejected(): void
    {
        $account = AccountFixture::account();
        $read = $this->readerWith($account, $this->snapshotOn($account, '2020-01-01'));
        $this->expectException(\App\Module\Accounts\Application\InvalidAccountBalanceInput::class);
        $read($account->id, '2025-10-18', '2026-10-20');
    }

    public function testExplicitFromReportsNotTruncated(): void
    {
        $account = AccountFixture::account();
        $history = ($this->readerWith($account, $this->snapshotOn($account, '2020-01-01')))($account->id, '2026-01-01', '2026-10-20');
        self::assertFalse($history->truncated);
        self::assertSame('2026-01-01', $history->from);
    }

    public function testOmittedFromOfAnotherWorkspaceAccountIsNotFound(): void
    {
        $account = AccountFixture::account();
        $caller = new FixedCallerWorkspace('99999999-0000-7000-8000-000000000001');
        $snapshots = new Double\InMemoryAccountBalanceSnapshotRepository($this->snapshotOn($account, '2024-02-29'));
        $read = new ReadAccountValuationHistory($caller, new InMemoryAccountRepository($account), new ResolveAccountValuations($snapshots, $this->createStub(BookedMovementTotals::class)), new WorkspaceCalendar(new MockClock('2026-10-20', 'UTC'), $caller, new FixedWorkspaceTimezoneReader()), $snapshots);
        $this->expectException(\App\Module\Accounts\Application\AccountNotFound::class);
        $read($account->id, null, '2026-10-20');
    }

    private function readerWith(\App\Module\Accounts\Domain\Account $account, \App\Module\Accounts\Domain\AccountBalanceSnapshot ...$stored): ReadAccountValuationHistory
    {
        $caller = new FixedCallerWorkspace(AccountFixture::WORKSPACE);
        $snapshots = new Double\InMemoryAccountBalanceSnapshotRepository(...$stored);
        $movements = $this->createStub(BookedMovementTotals::class);
        $movements->method('forWindows')->willReturnCallback(static function (WorkspaceScope $workspace, array $windows): array {
            $totals = [];
            foreach ($windows as $window) {
                self::assertInstanceOf(\App\Module\Accounts\Domain\MovementWindow::class, $window);
                $totals[$window->key()] = new \App\Module\Accounts\Domain\MovementTotal(null, 0, 0, false);
            }

            return $totals;
        });

        return new ReadAccountValuationHistory($caller, new InMemoryAccountRepository($account), new ResolveAccountValuations($snapshots, $movements), new WorkspaceCalendar(new MockClock('2026-10-20', 'UTC'), $caller, new FixedWorkspaceTimezoneReader()), $snapshots);
    }

    private function snapshotOn(\App\Module\Accounts\Domain\Account $account, string $day, string $id = '00000000-0000-7000-8000-0000000000a1', ?string $accountId = null): \App\Module\Accounts\Domain\AccountBalanceSnapshot
    {
        return new \App\Module\Accounts\Domain\AccountBalanceSnapshot(
            $id,
            $account->workspace,
            $accountId ?? $account->id,
            new \DateTimeImmutable($day, new \DateTimeZone('UTC')),
            new \App\Module\Foundation\Domain\AssetAmount(\App\Module\Foundation\Domain\DecimalValue::fromString('1000'), \App\Module\Foundation\Domain\AssetCode::fromString('EUR')),
            \App\Module\Accounts\Domain\BalanceSnapshotSource::MANUAL,
            \App\Module\Accounts\Domain\ReconciliationStatus::UNRECONCILED,
            null,
            1,
            new \DateTimeImmutable($day.'T10:00:00+00:00'),
            '00000000-0000-7000-8000-000000000001',
        );
    }
}
