<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Infrastructure\Persistence;

use App\Module\Accounts\Application\AccountGroupConflict;
use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountValuationMode;
use App\Module\Accounts\Domain\LiquidityLevel;
use App\Module\Accounts\Infrastructure\Persistence\DbalAccountGroupRepository;
use App\Module\Accounts\Infrastructure\Persistence\DbalAccountRepository;
use App\Module\Catalog\Domain\AccountKind;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Tests\Module\Accounts\Domain\AccountGroupFixture;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AccountGroupPersistenceTest extends KernelTestCase
{
    private const string OWN_GROUP = '00000000-0000-7000-8000-0000000000b1';
    private const string OWN_CHILD = '00000000-0000-7000-8000-0000000000b2';
    private const string OTHER_GROUP = '00000000-0000-7000-8000-0000000000b3';
    private const string OWN_GRANDCHILD = '00000000-0000-7000-8000-0000000000b4';
    private const string OWN_BRANCH = '00000000-0000-7000-8000-0000000000b5';
    private const string OWN_ACCOUNT = '00000000-0000-7000-8000-0000000000d1';

    private WorkspaceFixture $fixture;
    private DbalAccountGroupRepository $groups;
    private DbalAccountRepository $accounts;
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->fixture = new WorkspaceFixture($connection);
        $this->groups = new DbalAccountGroupRepository($connection);
        $this->accounts = new DbalAccountRepository($connection);
        $this->databaseReady = true;
        $this->fixture->reset();
        $this->fixture->seed();
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            $this->fixture->reset();
        }
        parent::tearDown();
    }

    public function testGroupsAreReadOnlyInsideTheirWorkspace(): void
    {
        $this->groups->add($this->group(self::OWN_GROUP, WorkspaceFixture::own()));
        $this->groups->add($this->group(self::OTHER_GROUP, WorkspaceFixture::other(), label: 'Voisin'));

        self::assertSame(
            [self::OWN_GROUP],
            array_column($this->groups->list(WorkspaceFixture::own(), false, 100, 0), 'id'),
        );
        self::assertNull($this->groups->find(WorkspaceFixture::own(), self::OTHER_GROUP));
        self::assertNotNull($this->groups->find(WorkspaceFixture::other(), self::OTHER_GROUP));
    }

    public function testActiveSiblingLabelsAreUniqueIgnoringCase(): void
    {
        $this->groups->add($this->group(self::OWN_GROUP, WorkspaceFixture::own()));

        $this->expectException(AccountGroupConflict::class);
        $this->groups->add($this->group(self::OWN_CHILD, WorkspaceFixture::own(), label: 'épargne'));
    }

    public function testAParentCannotComeFromAnotherWorkspace(): void
    {
        $this->groups->add($this->group(self::OTHER_GROUP, WorkspaceFixture::other()));

        $this->expectException(DbalException::class);
        $this->groups->add($this->group(
            self::OWN_GROUP,
            WorkspaceFixture::own(),
            parentId: self::OTHER_GROUP,
            depth: 2,
        ));
    }

    public function testTheDatabaseRejectsACycle(): void
    {
        $parent = $this->group(self::OWN_GROUP, WorkspaceFixture::own());
        $child = $this->group(self::OWN_CHILD, WorkspaceFixture::own(), label: 'Court terme', parentId: $parent->id, depth: 2);
        $this->groups->add($parent);
        $this->groups->add($child);

        $cycled = $parent->reconfigure(
            label: $parent->label,
            parentId: $child->id,
            sortOrder: $parent->sortOrder,
            depth: 3,
            updatedAt: new \DateTimeImmutable('2026-09-05T12:00:00+00:00'),
        );

        $this->expectException(DbalException::class);
        $this->expectExceptionMessageMatches('/cycle/');
        $this->groups->update($cycled, 1);
    }

    public function testAPrimaryGroupCannotComeFromAnotherWorkspace(): void
    {
        $this->groups->add($this->group(self::OTHER_GROUP, WorkspaceFixture::other()));
        $account = $this->account(self::OWN_ACCOUNT, WorkspaceFixture::own());
        $this->accounts->add($account);

        $this->expectException(DbalException::class);
        $this->accounts->update(
            $account->regroup(self::OTHER_GROUP, [], new \DateTimeImmutable('2026-09-05T12:00:00+00:00')),
            1,
        );
    }

    public function testATagCannotComeFromAnotherWorkspace(): void
    {
        $this->groups->add($this->group(self::OWN_GROUP, WorkspaceFixture::own()));
        $this->groups->add($this->group(self::OTHER_GROUP, WorkspaceFixture::other()));
        $account = $this->account(self::OWN_ACCOUNT, WorkspaceFixture::own());
        $this->accounts->add($account);

        $this->expectException(DbalException::class);
        $this->accounts->update(
            $account->regroup(self::OWN_GROUP, [self::OTHER_GROUP], new \DateTimeImmutable('2026-09-05T12:00:00+00:00')),
            1,
        );
    }

    public function testReparentingAMiddleNodeKeepsDescendantDepthsValid(): void
    {
        $root = $this->group(self::OWN_GROUP, WorkspaceFixture::own(), label: 'Patrimoine');
        $mid = $this->group(self::OWN_CHILD, WorkspaceFixture::own(), label: 'Épargne', parentId: $root->id, depth: 2);
        $leaf = $this->group(self::OWN_GRANDCHILD, WorkspaceFixture::own(), label: 'Livret', parentId: $mid->id, depth: 3);
        $branch = $this->group(self::OWN_BRANCH, WorkspaceFixture::own(), label: 'Court terme', parentId: $root->id, depth: 2);
        $this->groups->add($root);
        $this->groups->add($mid);
        $this->groups->add($leaf);
        $this->groups->add($branch);

        $now = new \DateTimeImmutable('2026-09-05T12:00:00+00:00');
        $moved = $mid->reconfigure($mid->label, $branch->id, $mid->sortOrder, 3, $now);
        self::assertTrue($this->groups->update($moved, 1));

        $descendants = $this->groups->descendantsForUpdate(WorkspaceFixture::own(), $mid->id);
        self::assertSame([self::OWN_GRANDCHILD], array_column($descendants, 'id'));
        $rebased = $descendants[0]->rebaseDepth(4, $now);
        self::assertTrue($this->groups->update($rebased, 1));

        $renamed = $this->groups->find(WorkspaceFixture::own(), $leaf->id)?->reconfigure(
            'Livret A',
            $leaf->parentId,
            $leaf->sortOrder,
            4,
            new \DateTimeImmutable('2026-09-05T13:00:00+00:00'),
        );
        self::assertNotNull($renamed);
        self::assertTrue($this->groups->update($renamed, 2));
        self::assertSame(4, $this->groups->find(WorkspaceFixture::own(), $leaf->id)?->depth);
    }

    public function testOptimisticVersioningRejectsAStaleUpdate(): void
    {
        $group = $this->group(self::OWN_GROUP, WorkspaceFixture::own());
        $this->groups->add($group);
        $renamed = $group->reconfigure(
            label: 'Liquidités',
            parentId: $group->parentId,
            sortOrder: $group->sortOrder,
            depth: $group->depth,
            updatedAt: new \DateTimeImmutable('2026-09-05T12:00:00+00:00'),
        );

        self::assertTrue($this->groups->update($renamed, 1));
        self::assertFalse($this->groups->update($renamed, 1));
        self::assertSame('Liquidités', $this->groups->find(WorkspaceFixture::own(), $group->id)?->label);
    }

    private function group(
        string $id,
        WorkspaceScope $workspace,
        string $label = 'Épargne',
        ?string $parentId = null,
        int $depth = 1,
    ): \App\Module\Accounts\Domain\AccountGroup {
        return AccountGroupFixture::group(
            id: $id,
            workspace: $workspace->id,
            label: $label,
            parentId: $parentId,
            depth: $depth,
        );
    }

    private function account(string $id, WorkspaceScope $workspace): Account
    {
        $now = new \DateTimeImmutable('2026-09-01T12:00:00+00:00');

        return new Account(
            id: $id,
            workspace: $workspace,
            label: 'Livret A Banque X',
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
            openedOn: new \DateTimeImmutable('2026-01-10', new \DateTimeZone('UTC')),
            closedOn: null,
            version: 1,
            createdAt: $now,
            updatedAt: $now,
        );
    }
}
