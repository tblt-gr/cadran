<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Infrastructure\Persistence;

use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountOrdering;
use App\Module\Accounts\Domain\AccountValuationMode;
use App\Module\Accounts\Domain\LiquidityLevel;
use App\Module\Accounts\Domain\RankedAccount;
use App\Module\Accounts\Infrastructure\Persistence\DbalAccountGroupRepository;
use App\Module\Accounts\Infrastructure\Persistence\DbalAccountOrderRepository;
use App\Module\Accounts\Infrastructure\Persistence\DbalAccountRepository;
use App\Module\Catalog\Domain\AccountKind;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Tests\Module\Accounts\Domain\AccountGroupFixture;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AccountOrderPersistenceTest extends KernelTestCase
{
    private const string GROUP = AccountGroupFixture::ID;
    private const string OTHER_GROUP = AccountGroupFixture::OTHER_ID;

    private Connection $connection;
    private WorkspaceFixture $fixture;
    private DbalAccountRepository $accounts;
    private DbalAccountOrderRepository $orders;
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->fixture = new WorkspaceFixture($connection);
        $this->accounts = new DbalAccountRepository($connection);
        $this->orders = new DbalAccountOrderRepository($connection);
        $this->databaseReady = true;
        $this->fixture->reset();
        $this->fixture->seed();
        $groups = new DbalAccountGroupRepository($connection);
        $groups->add(AccountGroupFixture::group(id: self::GROUP, workspace: WorkspaceFixture::OWN_WORKSPACE, label: 'Épargne'));
        $groups->add(AccountGroupFixture::group(id: self::OTHER_GROUP, workspace: WorkspaceFixture::OWN_WORKSPACE, label: 'Liquidités'));
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            $this->fixture->reset();
        }
        parent::tearDown();
    }

    public function testUnplacedAccountsFollowTheirCreationOrderAndReceiveDenseRanks(): void
    {
        $this->add('01', 'Zeta', self::GROUP, '2026-09-01T10:00:00+00:00');
        $this->add('02', 'Alpha', self::GROUP, '2026-09-01T11:00:00+00:00');

        $ranked = $this->accounts->listInGroupOrder(WorkspaceFixture::own(), self::GROUP, true, true, 0, 50);

        self::assertSame([[$this->id('01'), 1], [$this->id('02'), 2]], $this->pairs($ranked));
    }

    public function testASavedSequenceWinsAndAnUnplacedNewcomerGoesLast(): void
    {
        $this->add('01', 'Alpha', self::GROUP);
        $this->add('02', 'Beta', self::GROUP);
        $this->orders->save(WorkspaceFixture::own(), self::GROUP, [$this->id('02'), $this->id('01')], 1);
        $this->add('03', 'Aardvark', self::GROUP, '2026-09-02T10:00:00+00:00');

        self::assertSame(
            [$this->id('02'), $this->id('01'), $this->id('03')],
            $this->accounts->memberIdsInOrder(WorkspaceFixture::own(), self::GROUP, 100),
        );
    }

    public function testAnAccountRegroupedAfterAMoveIsAppendedToItsNewGroup(): void
    {
        $this->add('01', 'Alpha', self::GROUP);
        $this->add('02', 'Beta', self::GROUP);
        $this->add('03', 'Gamma', self::OTHER_GROUP);
        $this->orders->save(WorkspaceFixture::own(), self::GROUP, [$this->id('02'), $this->id('01')], 1);
        $this->orders->save(WorkspaceFixture::own(), self::OTHER_GROUP, [$this->id('03')], 1);

        $this->connection->update('account_financial_accounts', ['primary_group_id' => self::OTHER_GROUP], ['id' => $this->id('02')]);

        self::assertSame([$this->id('01')], $this->accounts->memberIdsInOrder(WorkspaceFixture::own(), self::GROUP, 100));
        self::assertSame(
            [$this->id('03'), $this->id('02')],
            $this->accounts->memberIdsInOrder(WorkspaceFixture::own(), self::OTHER_GROUP, 100),
        );
    }

    public function testHiddenMembersKeepTheirRankWhenLifecycleFiltersApply(): void
    {
        $this->add('01', 'Alpha', self::GROUP);
        $this->add('02', 'Beta', self::GROUP, closedOn: '2026-02-01');
        $this->add('03', 'Gamma', self::GROUP);
        $this->orders->save(WorkspaceFixture::own(), self::GROUP, [$this->id('01'), $this->id('02'), $this->id('03')], 1);

        self::assertSame(
            [[$this->id('01'), 1], [$this->id('03'), 3]],
            $this->pairs($this->accounts->listInGroupOrder(WorkspaceFixture::own(), self::GROUP, false, false, 0, 50)),
        );
        self::assertSame(
            [[$this->id('03'), 3]],
            $this->pairs($this->accounts->listInGroupOrder(WorkspaceFixture::own(), self::GROUP, false, false, 1, 50)),
        );
        self::assertCount(3, $this->accounts->memberIdsInOrder(WorkspaceFixture::own(), self::GROUP, 100));
    }

    public function testNetWorthListingFollowsTheSavedOrderOfEachGroupWithTheUngroupedLast(): void
    {
        $this->add('01', 'Alpha', self::GROUP);
        $this->add('02', 'Beta', self::GROUP);
        $this->add('03', 'Gamma', self::OTHER_GROUP);
        $this->add('04', 'Delta', self::OTHER_GROUP);
        $this->add('05', 'Epsilon', null);
        $this->add('06', 'Zeta', null);
        $this->orders->save(WorkspaceFixture::own(), self::GROUP, [$this->id('02'), $this->id('01')], 1);
        $this->orders->save(WorkspaceFixture::own(), self::OTHER_GROUP, [$this->id('04'), $this->id('03')], 1);
        $this->orders->save(WorkspaceFixture::own(), null, [$this->id('06'), $this->id('05')], 1);

        $listed = $this->netWorthIds();

        self::assertSame($this->ids(['02', '01']), $this->only($listed, ['01', '02']));
        self::assertSame($this->ids(['04', '03']), $this->only($listed, ['03', '04']));
        self::assertSame($this->ids(['06', '05']), array_slice($listed, -2));
    }

    public function testNetWorthListingReflectsAMoveOnTheNextReadAndDropsAStalePosition(): void
    {
        $this->add('01', 'Alpha', self::GROUP);
        $this->add('02', 'Beta', self::GROUP);
        $this->add('03', 'Gamma', self::GROUP);
        $this->orders->save(WorkspaceFixture::own(), self::GROUP, [$this->id('03'), $this->id('02'), $this->id('01')], 1);
        self::assertSame($this->ids(['03', '02', '01']), $this->netWorthIds());

        $this->orders->save(WorkspaceFixture::own(), self::GROUP, [$this->id('01'), $this->id('03'), $this->id('02')], 2);
        self::assertSame($this->ids(['01', '03', '02']), $this->netWorthIds());

        // Regrouped since the last move: the saved position names another group.
        $this->connection->update('account_financial_accounts', ['primary_group_id' => self::OTHER_GROUP], ['id' => $this->id('01')]);
        self::assertSame($this->ids(['01']), $this->only($this->netWorthIds(), ['01']));
        self::assertSame($this->ids(['03', '02']), $this->only($this->netWorthIds(), ['02', '03']));
    }

    public function testNetWorthListingFallsBackToCreationOrderWithoutAnyPosition(): void
    {
        $this->add('01', 'Zeta', self::GROUP, '2026-09-01T12:00:00+00:00');
        $this->add('02', 'Alpha', self::GROUP, '2026-09-01T10:00:00+00:00');
        $this->add('03', 'Mid', self::GROUP, '2026-09-01T11:00:00+00:00');

        self::assertSame($this->ids(['02', '03', '01']), $this->netWorthIds());
    }

    public function testNetWorthListingPlacesUnplacedAccountsAfterPlacedOnes(): void
    {
        $this->add('01', 'Alpha', self::GROUP, '2026-09-01T10:00:00+00:00');
        $this->add('02', 'Beta', self::GROUP, '2026-09-01T11:00:00+00:00');
        $this->add('03', 'Gamma', self::GROUP, '2026-09-01T12:00:00+00:00');
        $this->orders->save(WorkspaceFixture::own(), self::GROUP, [$this->id('03')], 1);

        self::assertSame($this->ids(['03', '01', '02']), $this->netWorthIds());
    }

    public function testNetWorthListingOrderMatchesTheOverviewForTheRowsBothShow(): void
    {
        $this->add('01', 'Alpha', self::GROUP);
        $this->add('02', 'Beta', self::GROUP, closedOn: '2026-02-01');
        $this->add('03', 'Gamma', self::GROUP);
        $this->add('04', 'Delta', self::GROUP);
        $this->orders->save(WorkspaceFixture::own(), self::GROUP, [$this->id('04'), $this->id('02'), $this->id('01'), $this->id('03')], 1);

        $overview = array_map(
            static fn (RankedAccount $row): string => $row->account->id,
            $this->accounts->listInGroupOrder(WorkspaceFixture::own(), self::GROUP, false, false, 0, 50),
        );
        $listed = $this->netWorthIds();

        self::assertSame($this->ids(['04', '01', '03']), $overview);
        self::assertSame($overview, array_values(array_intersect($listed, $overview)));
    }

    public function testNetWorthListingNeverReadsAnotherWorkspaceOrItsPositions(): void
    {
        $this->add('01', 'Alpha', self::GROUP);
        $this->add('02', 'Beta', self::GROUP);
        $this->add('03', 'Voisin', null, workspace: WorkspaceFixture::OTHER_WORKSPACE);
        $this->orders->save(WorkspaceFixture::own(), self::GROUP, [$this->id('02'), $this->id('01')], 1);

        self::assertSame($this->ids(['02', '01']), $this->netWorthIds());
        self::assertSame($this->ids(['03']), $this->netWorthIds(WorkspaceFixture::other()));
    }

    public function testNetWorthListingOfAnEmptyWorkspaceIsEmpty(): void
    {
        self::assertSame([], $this->netWorthIds());
    }

    /** @return list<string> */
    private function netWorthIds(?WorkspaceScope $workspace = null): array
    {
        $utc = new \DateTimeZone('UTC');

        return array_map(
            static fn (Account $account): string => $account->id,
            $this->accounts->listForNetWorthDuring(
                $workspace ?? WorkspaceFixture::own(),
                new \DateTimeImmutable('2026-09-01', $utc),
                new \DateTimeImmutable('2026-09-30', $utc),
                new \DateTimeZone('Europe/Paris'),
                100,
            ),
        );
    }

    /**
     * @param list<string> $suffixes
     *
     * @return list<string>
     */
    private function ids(array $suffixes): array
    {
        return array_map($this->id(...), $suffixes);
    }

    /**
     * @param list<string> $listed
     * @param list<string> $suffixes
     *
     * @return list<string>
     */
    private function only(array $listed, array $suffixes): array
    {
        return array_values(array_intersect($listed, $this->ids($suffixes)));
    }

    public function testPagesAreBoundedAndResumeAfterTheLastRank(): void
    {
        for ($index = 1; $index <= 55; ++$index) {
            $this->add(sprintf('%02d', $index), sprintf('Compte %02d', $index), null);
        }

        $first = $this->accounts->listInGroupOrder(WorkspaceFixture::own(), null, true, true, 0, 50);
        $second = $this->accounts->listInGroupOrder(WorkspaceFixture::own(), null, true, true, $first[49]->rank, 50);

        self::assertCount(50, $first);
        self::assertCount(5, $second);
        self::assertSame(51, $second[0]->rank);
        self::assertSame(55, $second[4]->rank);
    }

    public function testMembersOfAnotherWorkspaceAndAnotherGroupNeverAppear(): void
    {
        $this->add('01', 'Alpha', self::GROUP);
        $this->add('02', 'Beta', self::OTHER_GROUP);
        $this->add('03', 'Gamma', null);
        $this->add('04', 'Voisin', null, workspace: WorkspaceFixture::OTHER_WORKSPACE);

        self::assertSame([$this->id('01')], $this->accounts->memberIdsInOrder(WorkspaceFixture::own(), self::GROUP, 100));
        self::assertSame([$this->id('03')], $this->accounts->memberIdsInOrder(WorkspaceFixture::own(), null, 100));
        self::assertSame([$this->id('04')], $this->accounts->memberIdsInOrder(WorkspaceFixture::other(), null, 100));
    }

    public function testMembersAreCountedPerGroupUnderTheLifecycleFilters(): void
    {
        $this->add('01', 'Alpha', self::GROUP);
        $this->add('02', 'Beta', self::GROUP, closedOn: '2026-02-01');
        $this->add('03', 'Gamma', null);

        self::assertEquals(
            [self::GROUP => 1, AccountOrdering::UNGROUPED_KEY => 1],
            $this->accounts->countByPrimaryGroup(WorkspaceFixture::own(), false, false),
        );
        self::assertSame(2, $this->accounts->countByPrimaryGroup(WorkspaceFixture::own(), false, true)[self::GROUP]);
    }

    public function testTheVersionRowIsCreatedLockedAndAdvancedPerGroup(): void
    {
        self::assertSame(0, $this->orders->version(WorkspaceFixture::own(), self::GROUP));

        $this->connection->beginTransaction();
        self::assertSame(0, $this->orders->lockVersion(WorkspaceFixture::own(), self::GROUP));
        $this->orders->save(WorkspaceFixture::own(), self::GROUP, [], 1);
        $this->connection->commit();

        self::assertSame(1, $this->orders->version(WorkspaceFixture::own(), self::GROUP));
        self::assertSame(0, $this->orders->version(WorkspaceFixture::own(), null));
        self::assertSame(0, $this->orders->version(WorkspaceFixture::other(), self::GROUP));
        self::assertSame([self::GROUP => 1], $this->orders->versions(WorkspaceFixture::own()));
    }

    public function testTwoWritersOfTheSameGroupSerializeOnTheVersionRow(): void
    {
        $this->connection->beginTransaction();
        $this->orders->lockVersion(WorkspaceFixture::own(), self::GROUP);

        $other = DriverManager::getConnection($this->connection->getParams());
        try {
            $other->executeStatement("SET lock_timeout = '300ms'");
            $other->beginTransaction();
            try {
                (new DbalAccountOrderRepository($other))->lockVersion(WorkspaceFixture::own(), self::GROUP);
                self::fail('The second writer must wait for the first one.');
            } catch (LockWaitTimeoutException|DbalException $exception) {
                self::assertStringContainsString('lock', strtolower($exception->getMessage()));
            }
            $other->rollBack();

            // Another group is not blocked by the first lock.
            $other->beginTransaction();
            self::assertSame(0, (new DbalAccountOrderRepository($other))->lockVersion(WorkspaceFixture::own(), self::OTHER_GROUP));
            $other->rollBack();
        } finally {
            $other->close();
            $this->connection->rollBack();
        }
    }

    /**
     * @param list<RankedAccount> $ranked
     *
     * @return list<array{string, int}>
     */
    private function pairs(array $ranked): array
    {
        return array_map(static fn (RankedAccount $row): array => [$row->account->id, $row->rank], $ranked);
    }

    private function id(string $suffix): string
    {
        return '00000000-0000-7000-8000-0000000001'.$suffix;
    }

    private function add(
        string $suffix,
        string $label,
        ?string $groupId,
        string $createdAt = '2026-09-01T10:00:00+00:00',
        ?string $closedOn = null,
        string $workspace = WorkspaceFixture::OWN_WORKSPACE,
    ): void {
        $utc = new \DateTimeZone('UTC');
        $created = new \DateTimeImmutable($createdAt);

        $this->accounts->add(new Account(
            id: $this->id($suffix),
            workspace: WorkspaceScope::fromString($workspace),
            label: $label,
            assetCode: AssetCode::fromString('EUR'),
            kind: AccountKind::SAVINGS,
            productCode: null,
            productModelId: null,
            institution: null,
            maskedIdentifier: null,
            valuationMode: AccountValuationMode::TRANSACTIONS,
            liquidityLevel: LiquidityLevel::IMMEDIATE,
            includeInNetWorth: true,
            includeInEmergencyFund: false,
            openedOn: new \DateTimeImmutable('2026-01-10', $utc),
            closedOn: null === $closedOn ? null : new \DateTimeImmutable($closedOn, $utc),
            version: 1,
            createdAt: $created,
            updatedAt: $created,
            primaryGroupId: $groupId,
        ));
    }
}
