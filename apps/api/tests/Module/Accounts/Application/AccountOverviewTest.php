<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application;

use App\Module\Accounts\Application\AccountNotFound;
use App\Module\Accounts\Application\AccountOverviewCursorCodec;
use App\Module\Accounts\Application\AccountOverviewFigures;
use App\Module\Accounts\Application\InvalidAccountOverviewQuery;
use App\Module\Accounts\Application\ListAccountOverviewRows;
use App\Module\Accounts\Application\MoveAccount;
use App\Module\Accounts\Application\ReadAccountOverview;
use App\Module\Accounts\Application\ResolveAccountValuations;
use App\Module\Accounts\Application\ResolveNetWorthContributions;
use App\Module\Accounts\Application\StaleAccountOrder;
use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountBalanceSnapshot;
use App\Module\Accounts\Domain\AccountValuationMode;
use App\Module\Accounts\Domain\BalanceSnapshotSource;
use App\Module\Accounts\Domain\InvalidAccountMove;
use App\Module\Accounts\Domain\LiquidityLevel;
use App\Module\Accounts\Domain\ReconciliationStatus;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Catalog\Domain\AccountKind;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Tests\Module\Accounts\Application\Double\CollectingAuditEventRepository;
use App\Tests\Module\Accounts\Application\Double\EmptyBookedMovementTotals;
use App\Tests\Module\Accounts\Application\Double\FixedCallerWorkspace;
use App\Tests\Module\Accounts\Application\Double\FixedWorkspaceTimezoneReader;
use App\Tests\Module\Accounts\Application\Double\ImmediateTransactionBoundary;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountBalanceSnapshotRepository;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountGroupRepository;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountOrderRepository;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountRepository;
use App\Tests\Module\Accounts\Application\Double\SequenceUuidGenerator;
use App\Tests\Module\Accounts\Domain\AccountGroupFixture;
use App\Tests\Module\Reference\Application\Double\InMemoryAssetCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The unified account overview, with a hand-computed reference.
 *
 * On 5 September 2026 the eligible net worth is 1 000.00 EUR:
 *   A  600.00  group "Épargne" (child of "Liquidités")
 *   B  300.00  group "Liquidités"
 *   C  100.00  no group
 *   D   50.00  closed on 1 September, so outside the eligible net worth
 * "Épargne" is 60 %, "Liquidités" rolls A and B up once to 900.00 (90 %),
 * "Sans groupe" is 100.00 (10 %). A holds 60 %, B 30 %, C 10 % and D none,
 * whatever the lifecycle filter shows.
 */
final class AccountOverviewTest extends TestCase
{
    private const string WORKSPACE = AccountGroupFixture::WORKSPACE;
    private const string OTHER_WORKSPACE = AccountGroupFixture::OTHER_WORKSPACE;
    private const string PARENT = AccountGroupFixture::ID;
    private const string CHILD = AccountGroupFixture::CHILD_ID;
    private const string A = '00000000-0000-7000-8000-0000000000d1';
    private const string B = '00000000-0000-7000-8000-0000000000d2';
    private const string C = '00000000-0000-7000-8000-0000000000d3';
    private const string D = '00000000-0000-7000-8000-0000000000d4';
    private const string FOREIGN = '00000000-0000-7000-8000-0000000000d9';

    private InMemoryAccountOrderRepository $orders;
    private CollectingAuditEventRepository $trail;

    public function testGroupHeadingsCarryExactTotalsAndSharesOfEligibleNetWorth(): void
    {
        $overview = ($this->overview())('2026-09-05', false, false);

        self::assertSame('2026-09-05', $overview->asOf);
        $byGroup = [];
        foreach ($overview->groups as $group) {
            $byGroup[$group->groupId ?? 'none'] = $group;
        }

        self::assertSame([self::PARENT, self::CHILD, 'none'], array_keys($byGroup));
        self::assertSame('900.00', $byGroup[self::PARENT]->total?->amount);
        self::assertSame('90.00', $byGroup[self::PARENT]->share->percentDisplay);
        self::assertTrue($byGroup[self::PARENT]->isRollup);
        self::assertSame('600.00', $byGroup[self::CHILD]->total?->amount);
        self::assertSame('60.00', $byGroup[self::CHILD]->share->percentDisplay);
        self::assertFalse($byGroup[self::CHILD]->isRollup);
        self::assertSame('100.00', $byGroup['none']->total?->amount);
        self::assertSame('10.00', $byGroup['none']->share->percentDisplay);
        self::assertSame('CURRENT', $byGroup['none']->quality);
        self::assertNull($byGroup['none']->parentId);
    }

    public function testShowingClosedAccountsChangesNeitherTotalsNorTheDenominator(): void
    {
        $hidden = ($this->overview())('2026-09-05', false, false);
        $shown = ($this->overview())('2026-09-05', true, false);

        self::assertEquals(array_column($hidden->groups, 'total'), array_column($shown->groups, 'total'));
        self::assertEquals(array_column($hidden->groups, 'share'), array_column($shown->groups, 'share'));
        self::assertSame([1, 1, 1], array_column($hidden->groups, 'memberCount'));
        self::assertSame([2, 1, 1], array_column($shown->groups, 'memberCount'));
        self::assertSame(4, $shown->accountCount);
    }

    public function testAMissingValuationMakesEveryTotalNullWithItsReason(): void
    {
        $overview = ($this->overview(snapshots: array_slice($this->snapshots(), 0, 2)))('2026-09-05', false, false);

        foreach ($overview->groups as $group) {
            self::assertNull($group->total);
            self::assertSame('MISSING_VALUATION', $group->totalReason);
            self::assertNull($group->share->percent);
            self::assertSame('MISSING', $group->quality);
        }
    }

    public function testAZeroEligibleNetWorthPublishesTotalsButNoShare(): void
    {
        $overview = ($this->overview(accounts: [
            $this->account(self::A, 'Actif', self::PARENT),
            $this->account(self::B, 'Dette', self::PARENT, kind: AccountKind::LIABILITY),
        ], snapshots: [$this->snapshot(self::A, '2026-09-05', '500.00'), $this->snapshot(self::B, '2026-09-05', '500.00')]))('2026-09-05', false, false);

        self::assertSame('0.00', $overview->groups[0]->total?->amount);
        self::assertNull($overview->groups[0]->share->percent);
        self::assertSame('ZERO_ELIGIBLE_NET_WORTH', $overview->groups[0]->share->reason);
    }

    public function testRowsCarryTheirOwnShareAndTheValuationOfTheRequestedDay(): void
    {
        $page = ($this->rows())(self::PARENT, null, '2026-09-05', true, false);

        self::assertSame([self::B, self::D], array_column($page->items, 'id'));
        self::assertSame('30.00', $page->items[0]->share?->percentDisplay);
        self::assertSame('300.00', $page->items[0]->valuation?->amount);
        self::assertNull($page->items[1]->share?->percent);
        self::assertNull($page->items[1]->share?->reason);
        self::assertNull($page->nextCursor);
        self::assertSame(0, $page->orderVersion);
    }

    public function testRowsOfTheUngroupedSectionAreAddressedByANullGroup(): void
    {
        $page = ($this->rows())(null, null, '2026-09-05', false, false);

        self::assertSame([self::C], array_column($page->items, 'id'));
        self::assertSame('10.00', $page->items[0]->share?->percentDisplay);
    }

    public function testMoreThanFiftyMembersArePagedByAGroupBoundCursor(): void
    {
        $accounts = [];
        $snapshots = [];
        for ($index = 1; $index <= 120; ++$index) {
            $id = sprintf('00000000-0000-7000-8000-00000001%04d', $index);
            $accounts[] = $this->account($id, sprintf('Compte %03d', $index), self::PARENT);
            $snapshots[] = $this->snapshot($id, '2026-09-05', '10.00');
        }
        $rows = $this->rows(accounts: $accounts, snapshots: $snapshots);

        $first = $rows(self::PARENT, null, '2026-09-05', false, false);
        self::assertCount(50, $first->items);
        self::assertNotNull($first->nextCursor);
        $second = $rows(self::PARENT, $first->nextCursor, '2026-09-05', false, false);
        self::assertCount(50, $second->items);
        $third = $rows(self::PARENT, $second->nextCursor, '2026-09-05', false, false);
        self::assertCount(20, $third->items);
        self::assertNull($third->nextCursor);
        $all = array_merge(array_column($first->items, 'id'), array_column($second->items, 'id'), array_column($third->items, 'id'));
        self::assertCount(120, array_unique($all));
    }

    public function testACursorMintedForAnotherGroupIsRefused(): void
    {
        $accounts = [];
        for ($index = 1; $index <= 60; ++$index) {
            $accounts[] = $this->account(sprintf('00000000-0000-7000-8000-00000001%04d', $index), sprintf('Compte %03d', $index), self::PARENT);
        }
        $rows = $this->rows(accounts: $accounts, snapshots: []);
        $cursor = $rows(self::PARENT, null, '2026-09-05', false, false)->nextCursor;

        $this->expectException(InvalidAccountOverviewQuery::class);

        $rows(self::CHILD, $cursor, '2026-09-05', false, false);
    }

    public function testATamperedCursorIsRefused(): void
    {
        $this->expectException(InvalidAccountOverviewQuery::class);

        ($this->rows())(self::PARENT, 'AAAA.BBBB', '2026-09-05', false, false);
    }

    public function testAForeignWorkspaceGroupIsUnknownToTheCaller(): void
    {
        $this->expectException(AccountNotFound::class);

        ($this->rows(caller: self::OTHER_WORKSPACE))(self::PARENT, null, '2026-09-05', false, false);
    }

    public function testMovingAnAccountPersistsTheOrderAndAdvancesTheVersionAndTheAudit(): void
    {
        $accounts = [
            $this->account(self::A, 'Alpha', self::PARENT),
            $this->account(self::B, 'Beta', self::PARENT),
            $this->account(self::D, 'Delta', self::PARENT),
        ];
        $move = $this->move($accounts);

        $moved = $move(self::D, self::PARENT, self::A, 0);

        self::assertSame(1, $moved->orderVersion);
        self::assertSame(1, $moved->rank);
        $page = ($this->rows(accounts: $accounts))(self::PARENT, null, '2026-09-05', true, false);
        self::assertSame([self::D, self::A, self::B], array_column($page->items, 'id'));
        self::assertSame(1, $page->orderVersion);
        self::assertCount(1, $this->trail->events);
        self::assertSame('account.moved', $this->trail->events[0]->eventType);
        self::assertStringNotContainsString('Delta', json_encode($this->trail->events[0]->diff ?? '', JSON_THROW_ON_ERROR));
    }

    public function testMovingToTheEndWithANullAnchor(): void
    {
        $accounts = [$this->account(self::A, 'Alpha', self::PARENT), $this->account(self::B, 'Beta', self::PARENT)];

        $this->move($accounts)(self::A, self::PARENT, null, 0);

        $page = ($this->rows(accounts: $accounts))(self::PARENT, null, '2026-09-05', true, false);
        self::assertSame([self::B, self::A], array_column($page->items, 'id'));
    }

    public function testFilteredReorderPreservesTheOrderOfHiddenMembers(): void
    {
        $accounts = [
            $this->account(self::A, 'Alpha', self::PARENT),
            $this->account(self::B, 'Beta', self::PARENT, closedOn: '2026-02-01'),
            $this->account(self::C, 'Gamma', self::PARENT),
            $this->account(self::D, 'Delta', self::PARENT, closedOn: '2026-02-01'),
        ];
        $move = $this->move($accounts);

        // Seen with the default filter the owner sees [A, C]; moving C above A.
        $move(self::C, self::PARENT, self::A, 0);

        $all = ($this->rows(accounts: $accounts))(self::PARENT, null, '2026-09-05', true, false);
        self::assertSame([self::C, self::A, self::B, self::D], array_column($all->items, 'id'));
    }

    public function testAStaleVersionIsRefusedAndNothingChanges(): void
    {
        $accounts = [$this->account(self::A, 'Alpha', self::PARENT), $this->account(self::B, 'Beta', self::PARENT)];
        $move = $this->move($accounts);
        $move(self::B, self::PARENT, self::A, 0);

        try {
            $move(self::A, self::PARENT, null, 0);
            self::fail('A move read before the other one must be refused.');
        } catch (StaleAccountOrder) {
            self::assertSame(1, $this->orders->version(WorkspaceScope::fromString(self::WORKSPACE), self::PARENT));
            self::assertCount(1, $this->trail->events);
        }
    }

    public function testAnAccountOfAnotherGroupOrWorkspaceIsRefusedWithoutLeak(): void
    {
        $accounts = [
            $this->account(self::A, 'Alpha', self::PARENT),
            $this->account(self::B, 'Beta', self::CHILD),
            $this->account(self::FOREIGN, 'Voisin', self::PARENT, workspace: self::OTHER_WORKSPACE),
        ];
        $move = $this->move($accounts);

        foreach ([[self::B, self::PARENT, null], [self::A, self::CHILD, null], [self::A, self::PARENT, self::B], [self::A, self::PARENT, self::A], [self::A, null, null]] as [$id, $group, $before]) {
            try {
                $move($id, $group, $before, 0);
                self::fail('The move must be refused.');
            } catch (InvalidAccountMove) {
                $this->addToAssertionCount(1);
            }
        }
        foreach ([self::FOREIGN, '00000000-0000-7000-8000-0000000000ff'] as $id) {
            try {
                $move($id, self::PARENT, null, 0);
                self::fail('An unknown account must be refused.');
            } catch (AccountNotFound) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame(0, $this->orders->version(WorkspaceScope::fromString(self::WORKSPACE), self::PARENT));
        self::assertSame([], $this->trail->events);
    }

    public function testTheUngroupedSectionCanBeReordered(): void
    {
        $accounts = [$this->account(self::A, 'Alpha', null), $this->account(self::B, 'Beta', null)];

        $moved = $this->move($accounts)(self::B, null, self::A, 0);

        self::assertSame(1, $moved->rank);
        self::assertNull($moved->groupId);
    }

    /**
     * @param list<Account>|null                $accounts
     * @param list<AccountBalanceSnapshot>|null $snapshots
     */
    private function overview(?array $accounts = null, ?array $snapshots = null): ReadAccountOverview
    {
        $accounts ??= $this->accounts();
        $this->orders = new InMemoryAccountOrderRepository();
        $accountRepository = (new InMemoryAccountRepository(...$accounts))->orderedBy($this->orders);

        return new ReadAccountOverview(
            new FixedCallerWorkspace(self::WORKSPACE),
            $this->figures($accounts, $snapshots ?? $this->snapshots()),
            $accountRepository,
            $this->orders,
            InMemoryAssetCatalog::withCodes('EUR'),
        );
    }

    /**
     * @param list<Account>|null                $accounts
     * @param list<AccountBalanceSnapshot>|null $snapshots
     */
    private function rows(?array $accounts = null, ?array $snapshots = null, string $caller = self::WORKSPACE): ListAccountOverviewRows
    {
        $accounts ??= $this->accounts();
        $this->orders ??= new InMemoryAccountOrderRepository();
        $snapshotRepository = new InMemoryAccountBalanceSnapshotRepository(...($snapshots ?? $this->snapshots()));

        return new ListAccountOverviewRows(
            new FixedCallerWorkspace($caller),
            $this->figures($accounts, $snapshots ?? $this->snapshots()),
            $this->groupRepository(),
            (new InMemoryAccountRepository(...$accounts))->orderedBy($this->orders),
            $this->orders,
            new ResolveAccountValuations($snapshotRepository, new EmptyBookedMovementTotals()),
            InMemoryAssetCatalog::withCodes('EUR'),
            new AccountOverviewCursorCodec('unit-test-secret'),
        );
    }

    /** @param list<Account> $accounts */
    private function move(array $accounts): MoveAccount
    {
        $this->orders = new InMemoryAccountOrderRepository();
        $this->trail = new CollectingAuditEventRepository();

        return new MoveAccount(
            new FixedCallerWorkspace(self::WORKSPACE),
            (new InMemoryAccountRepository(...$accounts))->orderedBy($this->orders),
            $this->orders,
            new ImmediateTransactionBoundary(),
            new RecordAuditEvent($this->trail, new SequenceUuidGenerator()),
        );
    }

    /**
     * @param list<Account>                $accounts
     * @param list<AccountBalanceSnapshot> $snapshots
     */
    private function figures(array $accounts, array $snapshots): AccountOverviewFigures
    {
        return new AccountOverviewFigures(
            new ResolveNetWorthContributions(
                new InMemoryAccountRepository(...$accounts),
                new ResolveAccountValuations(new InMemoryAccountBalanceSnapshotRepository(...$snapshots), new EmptyBookedMovementTotals()),
                new FixedWorkspaceTimezoneReader(),
            ),
            $this->groupRepository(),
            new MockClock('2026-09-05 09:00:00', 'UTC'),
        );
    }

    private function groupRepository(): InMemoryAccountGroupRepository
    {
        return new InMemoryAccountGroupRepository(
            AccountGroupFixture::group(id: self::PARENT, label: 'Liquidités'),
            AccountGroupFixture::group(id: self::CHILD, label: 'Épargne', parentId: self::PARENT, depth: 2),
        );
    }

    /** @return list<Account> */
    private function accounts(): array
    {
        return [
            $this->account(self::A, 'Alpha', self::CHILD),
            $this->account(self::B, 'Beta', self::PARENT),
            $this->account(self::C, 'Gamma', null),
            $this->account(self::D, 'Delta', self::PARENT, closedOn: '2026-09-01'),
        ];
    }

    /** @return list<AccountBalanceSnapshot> */
    private function snapshots(): array
    {
        return [
            $this->snapshot(self::A, '2026-09-05', '600.00'),
            $this->snapshot(self::B, '2026-09-05', '300.00'),
            $this->snapshot(self::C, '2026-09-05', '100.00'),
            $this->snapshot(self::D, '2026-08-31', '50.00'),
        ];
    }

    private function account(
        string $id,
        string $label,
        ?string $groupId,
        AccountKind $kind = AccountKind::CURRENT,
        ?string $closedOn = null,
        string $workspace = self::WORKSPACE,
    ): Account {
        $now = new \DateTimeImmutable('2026-09-01T10:00:00+00:00');

        return new Account(
            id: $id,
            workspace: WorkspaceScope::fromString($workspace),
            label: $label,
            assetCode: AssetCode::fromString('EUR'),
            kind: $kind,
            productCode: null,
            productModelId: null,
            institution: null,
            maskedIdentifier: null,
            valuationMode: AccountValuationMode::TRANSACTIONS,
            liquidityLevel: LiquidityLevel::IMMEDIATE,
            includeInNetWorth: true,
            includeInEmergencyFund: false,
            openedOn: new \DateTimeImmutable('2026-01-10', new \DateTimeZone('UTC')),
            closedOn: null === $closedOn ? null : new \DateTimeImmutable($closedOn, new \DateTimeZone('UTC')),
            version: 1,
            createdAt: $now,
            updatedAt: $now,
            primaryGroupId: $groupId,
        );
    }

    private function snapshot(string $accountId, string $asOf, string $amount): AccountBalanceSnapshot
    {
        return new AccountBalanceSnapshot(
            id: sprintf('00000000-0000-7000-8000-%s%s', substr($accountId, -6), substr(str_replace('-', '', $asOf), -6)),
            workspace: WorkspaceScope::fromString(self::WORKSPACE),
            accountId: $accountId,
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
