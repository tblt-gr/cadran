<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application;

use App\Module\Accounts\Application\BookedMovementTotals;
use App\Module\Accounts\Application\NetWorthScopeTooLarge;
use App\Module\Accounts\Application\ResolveAccountValuations;
use App\Module\Accounts\Domain\AccountBalanceSnapshot;
use App\Module\Accounts\Domain\AccountBalanceSnapshotRepository;
use App\Module\Accounts\Domain\AccountValuationMode;
use App\Module\Accounts\Domain\BalanceSnapshotSource;
use App\Module\Accounts\Domain\MovementTotal;
use App\Module\Accounts\Domain\MovementWindow;
use App\Module\Accounts\Domain\ReconciliationStatus;
use App\Module\Accounts\Domain\ValuationBasis;
use App\Module\Accounts\Domain\ValuationQuality;
use App\Module\Catalog\Domain\AccountKind;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountBalanceSnapshotRepository;
use App\Tests\Module\Accounts\Domain\AccountFixture;
use PHPUnit\Framework\TestCase;

final class ResolveAccountValuationsTest extends TestCase
{
    public function testBeforeOpeningIsMissingEvenWithAnEarlySnapshotAndOpeningDayKeepsItsBalance(): void
    {
        foreach (AccountValuationMode::cases() as $mode) {
            $account = AccountFixture::account(kind: AccountValuationMode::PORTFOLIO === $mode ? AccountKind::PORTFOLIO : AccountKind::SAVINGS, valuationMode: $mode);
            $beforeOpening = $account->openedOn->modify('-1 day');
            $snapshots = $this->createMock(AccountBalanceSnapshotRepository::class);
            $snapshots->expects(self::once())->method('findLatestForAccountsOnDates')->willReturn([
                $beforeOpening->format('Y-m-d') => [$account->id => $this->anchor($account->id, '2026-01-08', '42.10')],
                $account->openedOn->format('Y-m-d') => [$account->id => $this->anchor($account->id, '2026-01-10', '1250.40')],
            ]);
            $movements = $this->createMock(BookedMovementTotals::class);
            $movements->expects(self::never())->method('forWindows');

            $result = (new ResolveAccountValuations($snapshots, $movements))->forAccounts($account->workspace, [$account], [$beforeOpening, $account->openedOn]);

            self::assertNull($result[$beforeOpening->format('Y-m-d')][$account->id]->amount);
            self::assertSame(ValuationQuality::MISSING, $result[$beforeOpening->format('Y-m-d')][$account->id]->quality);
            self::assertSame('1250.40', $result[$account->openedOn->format('Y-m-d')][$account->id]->amount?->value->toString());
        }
    }

    public function testModesAccountsAndDaysShareOneSnapshotAndOneMovementBatch(): void
    {
        $workspace = WorkspaceScope::fromString(AccountFixture::WORKSPACE);
        $transaction = AccountFixture::account();
        $snapshot = AccountFixture::account(id: '00000000-0000-7000-8000-0000000000d2', valuationMode: AccountValuationMode::SNAPSHOTS);
        $portfolio = AccountFixture::account(kind: AccountKind::PORTFOLIO, id: '00000000-0000-7000-8000-0000000000d3', valuationMode: AccountValuationMode::PORTFOLIO);
        $missing = AccountFixture::account(id: '00000000-0000-7000-8000-0000000000d4');
        $anchor = $this->anchor($transaction->id, '2026-09-30', '1250.40');
        $snapshots = $this->createMock(AccountBalanceSnapshotRepository::class);
        $snapshots->expects(self::once())->method('findLatestForAccountsOnDates')->willReturn([
            '2026-09-30' => [$transaction->id => $anchor],
            '2026-10-10' => [$transaction->id => $anchor, $snapshot->id => $this->anchor($snapshot->id, '2026-09-30', '11.00'), $portfolio->id => $this->anchor($portfolio->id, '2026-09-30', '22.00', BalanceSnapshotSource::CALCULATED)],
            '2030-01-01' => [$transaction->id => $anchor],
        ]);
        $movements = $this->createMock(BookedMovementTotals::class);
        $movements->expects(self::once())->method('forWindows')->willReturnCallback(static function (WorkspaceScope $scope, array $windows) use ($workspace): array {
            self::assertTrue($workspace->equals($scope));
            self::assertCount(2, $windows);
            $result = [];
            foreach ($windows as $window) {
                self::assertInstanceOf(MovementWindow::class, $window);
                self::assertSame('2026-09-30', $window->afterExclusive->format('Y-m-d'));
                $result[$window->key()] = new MovementTotal(DecimalValue::fromString('1457.90'), 3, 1, false);
            }

            return $result;
        });
        $result = (new ResolveAccountValuations($snapshots, $movements))->forAccounts($workspace, [$transaction, $snapshot, $portfolio, $missing], [new \DateTimeImmutable('2026-09-30'), new \DateTimeImmutable('2026-10-10'), new \DateTimeImmutable('2030-01-01')]);
        self::assertSame(ValuationBasis::SNAPSHOT, $result['2026-09-30'][$transaction->id]->basis);
        self::assertSame('2708.30', $result['2026-10-10'][$transaction->id]->amount?->value->toString());
        self::assertSame('2708.30', $result['2030-01-01'][$transaction->id]->amount?->value->toString());
        self::assertSame('11.00', $result['2026-10-10'][$snapshot->id]->amount?->value->toString());
        self::assertSame(ValuationBasis::PORTFOLIO_VALUATION, $result['2026-10-10'][$portfolio->id]->basis);
        self::assertNull($result['2026-10-10'][$missing->id]->amount);
        self::assertSame(ValuationQuality::MISSING, $result['2026-10-10'][$missing->id]->quality);
    }

    public function testLaterAndRetroactiveSnapshotsRestartOnlyTheirOwnWindows(): void
    {
        $account = AccountFixture::account();
        $repository = new InMemoryAccountBalanceSnapshotRepository($this->anchor($account->id, '2026-09-30', '1250.40'), $this->anchor($account->id, '2026-10-20', '2650.00'));
        $movements = $this->createStub(BookedMovementTotals::class);
        $movements->method('forWindows')->willReturnCallback(static function (WorkspaceScope $scope, array $windows): array {
            $result = [];
            foreach ($windows as $window) {
                self::assertInstanceOf(MovementWindow::class, $window);
                $result[$window->key()] = '2026-09-30' === $window->afterExclusive->format('Y-m-d')
                    ? new MovementTotal(DecimalValue::fromString('1457.90'), 3, 1, false)
                    : ('2026-10-04' === $window->afterExclusive->format('Y-m-d') ? new MovementTotal(DecimalValue::fromString('1500.00'), 2, 1, false) : new MovementTotal(null, 0, 0, false));
            }

            return $result;
        });
        $resolver = new ResolveAccountValuations($repository, $movements);
        $days = [new \DateTimeImmutable('2026-10-10'), new \DateTimeImmutable('2026-10-31')];
        $result = $resolver->forAccounts($account->workspace, [$account], $days);
        self::assertSame('2708.30', $result['2026-10-10'][$account->id]->amount?->value->toString());
        self::assertSame('2650.00', $result['2026-10-31'][$account->id]->amount?->value->toString());
        $repository->add($this->anchor($account->id, '2026-10-04', '1208.30'));
        $result = $resolver->forAccounts($account->workspace, [$account], $days);
        self::assertSame('2708.30', $result['2026-10-10'][$account->id]->amount?->value->toString());
        self::assertSame(2, $result['2026-10-10'][$account->id]->derivation?->movementCount);
        self::assertSame('2650.00', $result['2026-10-31'][$account->id]->amount?->value->toString());
    }

    public function testAllBalanceReadersAgreeForOneAccountAndDay(): void
    {
        $account = AccountFixture::account(productCode: null);
        $day = new \DateTimeImmutable('2026-10-10');
        $accounts = new Double\InMemoryAccountRepository($account);
        $snapshots = new InMemoryAccountBalanceSnapshotRepository($this->anchor($account->id, '2026-09-30', '1250.40'));
        $movements = $this->createStub(BookedMovementTotals::class);
        $movements->method('forWindows')->willReturnCallback(static function (WorkspaceScope $scope, array $windows): array {
            $result = [];
            foreach ($windows as $window) {
                self::assertInstanceOf(MovementWindow::class, $window);
                $result[$window->key()] = $window->upToInclusive >= new \DateTimeImmutable('2026-10-07')
                    ? new MovementTotal(DecimalValue::fromString('1457.90'), 3, 1, false)
                    : new MovementTotal(null, 0, 0, false);
            }

            return $result;
        });
        $resolver = new ResolveAccountValuations($snapshots, $movements);
        $caller = new Double\FixedCallerWorkspace(AccountFixture::WORKSPACE);
        $assets = \App\Tests\Module\Reference\Application\Double\InMemoryAssetCatalog::withCodes('EUR');
        $clock = new \Symfony\Component\Clock\MockClock('2026-10-10', 'UTC');
        $timezone = new Double\FixedWorkspaceTimezoneReader();
        $single = new \App\Module\Accounts\Application\ResolveAccountValuation($resolver, $assets, new \App\Module\Foundation\Application\WorkspaceCalendar($clock, $caller, $timezone));
        $list = (new \App\Module\Accounts\Application\ListAccounts($caller, $accounts, $resolver, $assets, new \App\Module\Foundation\Application\WorkspaceCalendar($clock, $caller, $timezone)))(false, false, null, null);
        $detail = (new \App\Module\Accounts\Application\ReadAccount($caller, $accounts, $single))($account->id);
        $worth = (new \App\Module\Accounts\Application\ResolveNetWorthContributions($accounts, $resolver, $timezone))->on($account->workspace, [$day]);
        $monthly = (new \App\Module\Accounts\Application\ReadMonthlyAccountFacts($accounts, $resolver, $timezone))($account->workspace, new \App\Module\Accounts\Domain\CalendarMonth(2026, 10), $day);
        $rules = \App\Module\Accounts\Domain\AccountRules::withoutProduct($account, $day, \App\Module\Accounts\Domain\AccountRuleOverrides::none());
        $presentation = (new \App\Module\Accounts\Application\PresentAccountRules($caller, $accounts, $resolver, new \App\Module\Accounts\Application\ResolveAuthorNames($this->createStub(\App\Module\Identity\Application\AuthenticationUserRepository::class))))($rules);
        self::assertSame('2708.30', $list->items[0]->valuation?->amount);
        self::assertSame('2708.30', $detail->valuation?->amount);
        self::assertSame('2708.30', $worth->on($day)[0]->amount?->value->toString());
        self::assertSame('2708.30', $monthly->accounts[0]->end->amount?->value->toString());
        self::assertSame('2708.30', $presentation->valuation?->amount?->value->toString());
        $ceiling = new \App\Module\Accounts\Domain\AccountCeiling(
            \App\Module\Catalog\Domain\RuleKind::BALANCE_CEILING,
            new AssetAmount(DecimalValue::fromString('2500.00'), AssetCode::fromString('EUR')),
            new \App\Module\Catalog\Domain\EffectivePeriod(new \DateTimeImmutable('2026-01-01'), null),
            null,
            null,
            AssetCode::fromString('EUR'),
        );
        $check = $ceiling->compare($presentation->valuation->amount);
        self::assertSame('2708.30', $check->measured?->value->toString());
        self::assertSame('208.30', $check->excess?->value->toString());
        self::assertSame('EXCEEDED', $check->status->value);
    }

    public function testBatchBoundsAreRefusedBeforeReadingAnyData(): void
    {
        $snapshots = $this->createMock(AccountBalanceSnapshotRepository::class);
        $snapshots->expects(self::never())->method('findLatestForAccountsOnDates');
        $movements = $this->createMock(BookedMovementTotals::class);
        $movements->expects(self::never())->method('forWindows');
        $resolver = new ResolveAccountValuations($snapshots, $movements);
        $this->expectException(NetWorthScopeTooLarge::class);
        $resolver->forAccounts(WorkspaceScope::fromString(AccountFixture::WORKSPACE), [AccountFixture::account()], array_fill(0, 61, new \DateTimeImmutable('2026-10-10')));
    }

    private function anchor(string $accountId, string $day, string $amount, BalanceSnapshotSource $source = BalanceSnapshotSource::MANUAL): AccountBalanceSnapshot
    {
        return new AccountBalanceSnapshot(
            id: '00000000-0000-7000-8000-'.substr(md5($accountId.$day), 0, 12),
            workspace: WorkspaceScope::fromString(AccountFixture::WORKSPACE),
            accountId: $accountId,
            asOf: new \DateTimeImmutable($day),
            amount: new AssetAmount(DecimalValue::fromString($amount), AssetCode::fromString('EUR')),
            source: $source,
            reconciliationStatus: ReconciliationStatus::UNRECONCILED,
            comment: null,
            version: 1,
            recordedAt: new \DateTimeImmutable('2026-10-31T10:00:00+00:00'),
            recordedBy: '00000000-0000-7000-8000-000000000001',
        );
    }
}
