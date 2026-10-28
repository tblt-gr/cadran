<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Infrastructure\Persistence;

use App\Module\Accounts\Application\InvalidAccountGroupInput;
use App\Module\Accounts\Domain\AccountGroup;
use App\Module\Accounts\Infrastructure\Persistence\DbalAccountGroupRepository;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AccountGroupOrderPersistenceTest extends KernelTestCase
{
    private const string PARENT = '00000000-0000-7000-8000-0000000000b1';
    private const string A = '00000000-0000-7000-8000-0000000000c1';
    private const string B = '00000000-0000-7000-8000-0000000000c2';
    private const string C = '00000000-0000-7000-8000-0000000000c3';
    private const string FOREIGN = '00000000-0000-7000-8000-0000000000c9';

    private Connection $connection;
    private WorkspaceFixture $fixture;
    private DbalAccountGroupRepository $groups;
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->fixture = new WorkspaceFixture($connection);
        $this->groups = new DbalAccountGroupRepository($connection);
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

    public function testRootSiblingsAreReorderedWithTheNullParentComparison(): void
    {
        $this->insert(self::A, 'A', null, 0);
        $this->insert(self::B, 'B', null, 1);
        $this->insert(self::C, 'C', null, 2);
        $this->insert(self::FOREIGN, 'Foreign', null, 0, WorkspaceFixture::OTHER_WORKSPACE);

        $this->groups->saveSiblingOrder(WorkspaceFixture::own(), null, [self::C, self::A, self::B]);

        self::assertSame([self::C, self::A, self::B], array_column($this->groups->lockSiblings(WorkspaceFixture::own(), null, 10), 'id'));
        self::assertSame([0, 1, 2], $this->orders([self::C, self::A, self::B]));
        self::assertSame([0], $this->orders([self::FOREIGN]));
        self::assertSame([1, 1, 1], $this->versions([self::A, self::B, self::C]));
    }

    public function testChildSiblingsAreReorderedWithoutTouchingTheParentLevel(): void
    {
        $this->insert(self::PARENT, 'Parent', null, 5);
        $this->insert(self::A, 'A', self::PARENT, 0, depth: 2);
        $this->insert(self::B, 'B', self::PARENT, 1, depth: 2);

        $this->groups->saveSiblingOrder(WorkspaceFixture::own(), self::PARENT, [self::B, self::A]);

        self::assertSame([1, 0], $this->orders([self::A, self::B]));
        self::assertSame([5], $this->orders([self::PARENT]));
    }

    public function testASaveNeverRewritesRowsOfAnotherWorkspaceEvenWithTheirIds(): void
    {
        $this->insert(self::FOREIGN, 'Foreign', null, 7, WorkspaceFixture::OTHER_WORKSPACE);
        $this->insert(self::A, 'A', null, 0);

        $this->groups->saveSiblingOrder(WorkspaceFixture::own(), null, [self::A, self::FOREIGN]);

        self::assertSame([7], $this->orders([self::FOREIGN]));
        self::assertSame([], array_column($this->groups->lockSiblings(WorkspaceFixture::other(), self::PARENT, 10), 'id'));
    }

    public function testAppendPositionFollowsTheLastSiblingArchivedOnesIncluded(): void
    {
        self::assertSame(0, $this->groups->appendPosition(WorkspaceFixture::own(), null));

        $this->insert(self::A, 'A', null, 4);
        $this->connection->update('account_groups', ['archived_at' => '2026-09-01 12:00:00+00'], ['id' => self::A]);
        $this->insert(self::FOREIGN, 'Foreign', null, 90, WorkspaceFixture::OTHER_WORKSPACE);

        self::assertSame(5, $this->groups->appendPosition(WorkspaceFixture::own(), null));
    }

    public function testASiblingAtTheCapTriggersARenumberingInSavedOrder(): void
    {
        $this->insert(self::A, 'A', null, 3);
        $this->insert(self::B, 'B', null, AccountGroup::MAX_SORT_ORDER);
        $this->insert(self::C, 'C', null, 10);

        $position = $this->groups->appendPosition(WorkspaceFixture::own(), null);

        self::assertSame(3, $position);
        self::assertSame([0, 2, 1], $this->orders([self::A, self::B, self::C]));
        self::assertSame([1, 1, 1], $this->versions([self::A, self::B, self::C]));
    }

    public function testTooManySiblingsToRenumberAreRefusedInsteadOfOverflowing(): void
    {
        $this->insert(self::A, 'A', null, AccountGroup::MAX_SORT_ORDER);
        $this->connection->executeStatement(
            "INSERT INTO account_groups (id, workspace_id, label, parent_id, sort_order, depth, version, created_at, updated_at)
             SELECT gen_random_uuid(), :workspace, 'g' || n, NULL, n, 1, 1, now(), now() FROM generate_series(1, 1001) AS n",
            ['workspace' => WorkspaceFixture::OWN_WORKSPACE],
        );

        $this->expectException(InvalidAccountGroupInput::class);

        $this->groups->appendPosition(WorkspaceFixture::own(), null);
    }

    private function insert(string $id, string $label, ?string $parentId, int $sortOrder, string $workspace = WorkspaceFixture::OWN_WORKSPACE, int $depth = 1): void
    {
        $this->connection->insert('account_groups', [
            'id' => $id,
            'workspace_id' => $workspace,
            'label' => $label,
            'parent_id' => $parentId,
            'sort_order' => $sortOrder,
            'depth' => $depth,
            'version' => 1,
            'created_at' => '2026-09-01 12:00:00+00',
            'updated_at' => '2026-09-01 12:00:00+00',
        ]);
    }

    /**
     * @param list<string> $ids
     *
     * @return list<int>
     */
    private function orders(array $ids): array
    {
        return $this->column('sort_order', $ids);
    }

    /**
     * @param list<string> $ids
     *
     * @return list<int>
     */
    private function versions(array $ids): array
    {
        return $this->column('version', $ids);
    }

    /**
     * @param list<string> $ids
     *
     * @return list<int>
     */
    private function column(string $column, array $ids): array
    {
        $values = [];
        foreach ($ids as $id) {
            $value = $this->connection->fetchOne('SELECT '.$column.' FROM account_groups WHERE id = :id', ['id' => $id]);
            self::assertTrue(is_int($value) || is_string($value));
            $values[] = (int) $value;
        }

        return $values;
    }
}
