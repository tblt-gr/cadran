<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application;

use App\Module\Accounts\Application\AccountNotFound;
use App\Module\Accounts\Application\AccountOverviewCursorCodec;
use App\Module\Accounts\Application\AccountOverviewFigures;
use App\Module\Accounts\Application\AccountOverviewGrouping;
use App\Module\Accounts\Application\AccountOverviewRowPage;
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
    private const string E = '00000000-0000-7000-8000-0000000000d5';
    private const string F = '00000000-0000-7000-8000-0000000000d6';
    private const string FOREIGN = '00000000-0000-7000-8000-0000000000d9';

    private const string ROOT = AccountGroupFixture::ID;
    private const string OTHER_ROOT = AccountGroupFixture::OTHER_ID;
    private const string SUB = AccountGroupFixture::CHILD_ID;
    private const string LEAF = AccountGroupFixture::GRANDCHILD_ID;

    private InMemoryAccountOrderRepository $orders;
    /** @var list<\App\Module\Accounts\Domain\AccountGroup>|null */
    private ?array $groupSet = null;
    private CollectingAuditEventRepository $trail;

    public function testSubGroupHeadingsCarryDirectTotalsSharesAndTheParentLabel(): void
    {
        $overview = ($this->overview())('2026-09-05', false, false, AccountOverviewGrouping::PRIMARY);

        self::assertSame('2026-09-05', $overview->asOf);
        $byGroup = [];
        foreach ($overview->groups as $group) {
            $byGroup[$group->groupId ?? 'none'] = $group;
        }

        self::assertSame([self::PARENT, self::CHILD, 'none'], array_keys($byGroup));
        self::assertSame('300.00', $byGroup[self::PARENT]->total?->amount);
        self::assertSame('30.00', $byGroup[self::PARENT]->share->percentDisplay);
        self::assertNull($byGroup[self::PARENT]->parentLabel);
        self::assertSame('600.00', $byGroup[self::CHILD]->total?->amount);
        self::assertSame('60.00', $byGroup[self::CHILD]->share->percentDisplay);
        self::assertSame('Liquidités', $byGroup[self::CHILD]->parentLabel);
        self::assertSame('100.00', $byGroup['none']->total?->amount);
        self::assertSame('10.00', $byGroup['none']->share->percentDisplay);
        self::assertSame('CURRENT', $byGroup['none']->quality);
        self::assertNull($byGroup['none']->parentId);
        self::assertNull($byGroup['none']->parentLabel);
    }

    public function testParentHeadingsRollTheWholeSubtreeUpOnceAndNeverShowSubGroups(): void
    {
        $overview = ($this->overview())('2026-09-05', false, false);

        self::assertSame([self::PARENT, null], array_column($overview->groups, 'groupId'));
        self::assertSame('900.00', $overview->groups[0]->total?->amount);
        self::assertSame('90.00', $overview->groups[0]->share->percentDisplay);
        self::assertSame(2, $overview->groups[0]->memberCount);
        self::assertNull($overview->groups[0]->parentLabel);
        self::assertSame('100.00', $overview->groups[1]->total?->amount);
    }

    /**
     * Net worth 1 000.00: C 300 in ROOT, B 200 in SUB, A 100 in LEAF (ROOT > SUB > LEAF),
     * E 150 in OTHER_ROOT, F 250 ungrouped.
     * Roll-ups: ROOT 600 (60 %), OTHER_ROOT 150 (15 %), ungrouped 250 (25 %).
     * Direct: ROOT 300, SUB 200, LEAF 100, OTHER_ROOT 150, ungrouped 250.
     */
    public function testAThreeLevelTreeGivesRollupsInParentModeAndDirectTotalsInSubGroupMode(): void
    {
        $this->groupSet = $this->threeLevelTree();
        $accounts = $this->threeLevelAccounts();
        $snapshots = $this->threeLevelSnapshots();

        $parent = ($this->overview($accounts, $snapshots))('2026-09-05', false, false);
        self::assertSame([self::ROOT, self::OTHER_ROOT, null], array_column($parent->groups, 'groupId'));
        self::assertSame(['600.00', '150.00', '250.00'], array_map(static fn ($g) => $g->total?->amount, $parent->groups));
        self::assertSame(['60.00', '15.00', '25.00'], array_map(static fn ($g) => $g->share->percentDisplay, $parent->groups));
        self::assertSame([3, 1, 1], array_column($parent->groups, 'memberCount'));

        $primary = ($this->overview($accounts, $snapshots))('2026-09-05', false, false, AccountOverviewGrouping::PRIMARY);
        self::assertSame([self::ROOT, self::SUB, self::LEAF, self::OTHER_ROOT, null], array_column($primary->groups, 'groupId'));
        self::assertSame(['300.00', '200.00', '100.00', '150.00', '250.00'], array_map(static fn ($g) => $g->total?->amount, $primary->groups));
        self::assertSame(['30.00', '20.00', '10.00', '15.00', '25.00'], array_map(static fn ($g) => $g->share->percentDisplay, $primary->groups));
        self::assertSame([null, 'Root', 'Sub', null, null], array_column($primary->groups, 'parentLabel'));
        self::assertSame([1, 1, 1, 1, 1], array_column($primary->groups, 'memberCount'));
    }

    public function testAParentSectionExistsOnlyWhenItsSubtreeHasAVisibleMember(): void
    {
        $this->groupSet = $this->threeLevelTree();
        $accounts = [$this->account(self::A, 'A', self::LEAF, closedOn: '2026-09-01')];

        $hidden = ($this->overview($accounts, []))('2026-09-05', false, false);
        $shown = ($this->overview($accounts, []))('2026-09-05', true, false);

        self::assertSame([], $hidden->groups);
        self::assertSame([self::ROOT], array_column($shown->groups, 'groupId'));
        self::assertSame(1, $shown->groups[0]->memberCount);
    }

    public function testAnEmptyGroupHasNoSectionInEitherMode(): void
    {
        $this->groupSet = $this->threeLevelTree();
        $accounts = [$this->account(self::A, 'A', self::SUB)];
        $snapshots = [$this->snapshot(self::A, '2026-09-05', '10.00')];

        $primary = ($this->overview($accounts, $snapshots))('2026-09-05', false, false, AccountOverviewGrouping::PRIMARY);

        self::assertSame([self::SUB], array_column($primary->groups, 'groupId'));
    }

    public function testShowingClosedAccountsChangesNeitherTotalsNorTheDenominator(): void
    {
        $hidden = ($this->overview())('2026-09-05', false, false, AccountOverviewGrouping::PRIMARY);
        $shown = ($this->overview())('2026-09-05', true, false, AccountOverviewGrouping::PRIMARY);

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
        $page = ($this->rows())(self::PARENT, null, '2026-09-05', true, false, AccountOverviewGrouping::PRIMARY);

        self::assertSame([self::B, self::D], $this->ids($page));
        self::assertSame('30.00', $page->items[0]->account->share?->percentDisplay);
        self::assertSame('300.00', $page->items[0]->account->valuation?->amount);
        self::assertNull($page->items[1]->account->share?->percent);
        self::assertNull($page->items[1]->account->share?->reason);
        self::assertSame(self::PARENT, $page->items[0]->account->primaryGroupId);
        self::assertSame('Liquidités', $page->items[0]->primaryGroupLabel);
        self::assertNull($page->items[0]->primaryGroupParentLabel);
        self::assertSame(0, $page->items[0]->groupOrderVersion);
        self::assertNull($page->nextCursor);
        self::assertSame(0, $page->orderVersion);
    }

    public function testRowsOfTheUngroupedSectionAreAddressedByANullGroup(): void
    {
        $page = ($this->rows())(null, null, '2026-09-05', false, false);

        self::assertSame([self::C], $this->ids($page));
        self::assertSame('10.00', $page->items[0]->account->share?->percentDisplay);
        self::assertNull($page->items[0]->primaryGroupLabel);
        self::assertNull($page->items[0]->primaryGroupParentLabel);
    }

    public function testParentModeRowsConcatenateTheSubtreeInDepthFirstThenSavedOrder(): void
    {
        $this->groupSet = $this->threeLevelTree();
        $accounts = $this->threeLevelAccounts();
        $page = ($this->rows($accounts, $this->threeLevelSnapshots()))(self::ROOT, null, '2026-09-05', false, false);

        self::assertSame([self::C, self::B, self::A], $this->ids($page));
        self::assertSame([null, 'Root', 'Sub'], array_column($page->items, 'primaryGroupParentLabel'));
        self::assertSame([self::ROOT, self::SUB, self::LEAF], array_map(static fn ($row) => $row->account->primaryGroupId, $page->items));
        self::assertSame(['Root', 'Sub', 'Leaf'], array_column($page->items, 'primaryGroupLabel'));
        self::assertSame('30.00', $page->items[0]->account->share?->percentDisplay);
    }

    public function testEachVisibleAccountAppearsExactlyOnceAcrossTheSectionsOfAMode(): void
    {
        $this->groupSet = $this->threeLevelTree();
        $accounts = $this->threeLevelAccounts();
        $snapshots = $this->threeLevelSnapshots();

        foreach ([AccountOverviewGrouping::PARENT, AccountOverviewGrouping::PRIMARY] as $grouping) {
            $seen = [];
            foreach (($this->overview($accounts, $snapshots))('2026-09-05', false, false, $grouping)->groups as $section) {
                $page = ($this->rows($accounts, $snapshots))($section->groupId, null, '2026-09-05', false, false, $grouping);
                self::assertCount($section->memberCount, $page->items);
                array_push($seen, ...$this->ids($page));
            }
            sort($seen);
            $expected = array_map(static fn (Account $account) => $account->id, $accounts);
            sort($expected);
            self::assertSame($expected, $seen, $grouping->value);
        }
    }

    public function testParentModeRowsReflectEachPrimaryGroupOrderVersionAndSavedOrder(): void
    {
        $this->groupSet = $this->threeLevelTree();
        $accounts = [
            $this->account(self::A, 'A', self::SUB),
            $this->account(self::B, 'B', self::SUB),
            $this->account(self::C, 'C', self::ROOT),
        ];
        $this->move($accounts)(self::B, self::SUB, self::A, 0);
        $rows = $this->rows($accounts, []);

        $page = $rows(self::ROOT, null, '2026-09-05', false, false);

        self::assertSame([self::C, self::B, self::A], $this->ids($page));
        self::assertSame([0, 1, 1], array_column($page->items, 'groupOrderVersion'));
    }

    public function testMoreThanFiftySubtreeMembersAcrossSubGroupsPageOnceEach(): void
    {
        $this->groupSet = $this->threeLevelTree();
        $accounts = [];
        for ($index = 1; $index <= 120; ++$index) {
            $group = match (true) {
                $index <= 40 => self::ROOT,
                $index <= 80 => self::SUB,
                default => self::LEAF,
            };
            $accounts[] = $this->account(sprintf('00000000-0000-7000-8000-00000001%04d', $index), sprintf('Compte %03d', $index), $group);
        }
        $rows = $this->rows($accounts, []);

        $first = $rows(self::ROOT, null, '2026-09-05', false, false);
        $second = $rows(self::ROOT, $first->nextCursor, '2026-09-05', false, false);
        $third = $rows(self::ROOT, $second->nextCursor, '2026-09-05', false, false);

        self::assertSame([50, 50, 20], [count($first->items), count($second->items), count($third->items)]);
        self::assertNull($third->nextCursor);
        $all = [...$this->ids($first), ...$this->ids($second), ...$this->ids($third)];
        self::assertCount(120, array_unique($all));
        self::assertSame(array_map(static fn (Account $account) => $account->id, $accounts), $all);
    }

    public function testACursorMintedInOneModeIsRefusedInTheOther(): void
    {
        $this->groupSet = $this->threeLevelTree();
        $accounts = [];
        for ($index = 1; $index <= 60; ++$index) {
            $accounts[] = $this->account(sprintf('00000000-0000-7000-8000-00000001%04d', $index), sprintf('Compte %03d', $index), self::ROOT);
        }
        $rows = $this->rows($accounts, []);
        $cursor = $rows(self::ROOT, null, '2026-09-05', false, false)->nextCursor;
        self::assertNotNull($cursor);

        $this->expectException(InvalidAccountOverviewQuery::class);

        $rows(self::ROOT, $cursor, '2026-09-05', false, false, AccountOverviewGrouping::PRIMARY);
    }

    public function testAMoveInADescendantGroupMakesAParentModeCursorStale(): void
    {
        $this->groupSet = $this->threeLevelTree();
        $accounts = [];
        for ($index = 1; $index <= 60; ++$index) {
            $accounts[] = $this->account(sprintf('00000000-0000-7000-8000-00000001%04d', $index), sprintf('Compte %03d', $index), 1 === $index % 2 ? self::ROOT : self::LEAF);
        }
        $rows = $this->rows($accounts, []);
        $cursor = $rows(self::ROOT, null, '2026-09-05', false, false)->nextCursor;
        $this->orders->bump(WorkspaceScope::fromString(self::WORKSPACE), self::LEAF);

        $this->expectException(StaleAccountOrder::class);

        $rows(self::ROOT, $cursor, '2026-09-05', false, false);
    }

    public function testParentModeSubtreeNeverIncludesAnotherWorkspaceOrSiblingRoot(): void
    {
        $this->groupSet = $this->threeLevelTree();
        $accounts = [
            $this->account(self::A, 'A', self::LEAF),
            $this->account(self::E, 'E', self::OTHER_ROOT),
            $this->account(self::FOREIGN, 'Voisin', self::LEAF, workspace: self::OTHER_WORKSPACE),
        ];

        $page = ($this->rows($accounts, []))(self::ROOT, null, '2026-09-05', false, false);

        self::assertSame([self::A], $this->ids($page));
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
        $all = array_merge($this->ids($first), $this->ids($second), $this->ids($third));
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
        self::assertSame([self::D, self::A, self::B], $this->ids($page));
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
        self::assertSame([self::B, self::A], $this->ids($page));
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
        self::assertSame([self::C, self::A, self::B, self::D], $this->ids($all));
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
        return new InMemoryAccountGroupRepository(...($this->groupSet ?? [
            AccountGroupFixture::group(id: self::PARENT, label: 'Liquidités'),
            AccountGroupFixture::group(id: self::CHILD, label: 'Épargne', parentId: self::PARENT, depth: 2),
        ]));
    }

    /** @return list<\App\Module\Accounts\Domain\AccountGroup> */
    private function threeLevelTree(): array
    {
        return [
            AccountGroupFixture::group(id: self::ROOT, label: 'Root'),
            AccountGroupFixture::group(id: self::SUB, label: 'Sub', parentId: self::ROOT, depth: 2),
            AccountGroupFixture::group(id: self::LEAF, label: 'Leaf', parentId: self::SUB, depth: 3),
            AccountGroupFixture::group(id: self::OTHER_ROOT, label: 'Other', sortOrder: 1),
        ];
    }

    /** @return list<Account> */
    private function threeLevelAccounts(): array
    {
        return [
            $this->account(self::A, 'A', self::LEAF),
            $this->account(self::B, 'B', self::SUB),
            $this->account(self::C, 'C', self::ROOT),
            $this->account(self::E, 'E', self::OTHER_ROOT),
            $this->account(self::F, 'F', null),
        ];
    }

    /** @return list<AccountBalanceSnapshot> */
    private function threeLevelSnapshots(): array
    {
        return [
            $this->snapshot(self::A, '2026-09-05', '100.00'),
            $this->snapshot(self::B, '2026-09-05', '200.00'),
            $this->snapshot(self::C, '2026-09-05', '300.00'),
            $this->snapshot(self::E, '2026-09-05', '150.00'),
            $this->snapshot(self::F, '2026-09-05', '250.00'),
        ];
    }

    /** @return list<string> */
    private function ids(AccountOverviewRowPage $page): array
    {
        return array_map(static fn ($row): string => $row->account->id, $page->items);
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
