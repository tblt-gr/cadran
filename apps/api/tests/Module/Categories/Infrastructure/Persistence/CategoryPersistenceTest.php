<?php

declare(strict_types=1);

namespace App\Tests\Module\Categories\Infrastructure\Persistence;

use App\Module\Categories\Application\CategoryConflict;
use App\Module\Categories\Domain\AnalyticAxis;
use App\Module\Categories\Domain\Category;
use App\Module\Categories\Domain\CategoryType;
use App\Module\Categories\Infrastructure\Persistence\DbalCategoryRepository;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CategoryPersistenceTest extends KernelTestCase
{
    private Connection $connection;
    private WorkspaceFixture $fixture;
    private DbalCategoryRepository $repository;
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->fixture = new WorkspaceFixture($connection);
        $this->repository = new DbalCategoryRepository($connection);
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

    public function testCategoriesAreReadOnlyInsideTheirWorkspace(): void
    {
        $this->repository->add($this->category('00000000-0000-7000-8000-0000000000c1', WorkspaceFixture::own()));
        $this->repository->add($this->category('00000000-0000-7000-8000-0000000000c2', WorkspaceFixture::other()));

        self::assertSame(
            ['00000000-0000-7000-8000-0000000000c1'],
            array_column($this->repository->list(WorkspaceFixture::own(), false, 100, 0), 'id'),
        );
        self::assertNull($this->repository->find(WorkspaceFixture::own(), '00000000-0000-7000-8000-0000000000c2'));
        self::assertNotNull($this->repository->find(WorkspaceFixture::other(), '00000000-0000-7000-8000-0000000000c2'));
    }

    public function testActiveSiblingLabelsAreUniqueIgnoringCase(): void
    {
        $this->repository->add($this->category('00000000-0000-7000-8000-0000000000c1', WorkspaceFixture::own()));

        $this->expectException(CategoryConflict::class);
        $this->repository->add($this->category(
            '00000000-0000-7000-8000-0000000000c2',
            WorkspaceFixture::own(),
            label: 'restaurants',
        ));
    }

    public function testTheSameLabelCanExistUnderAnotherParentAndType(): void
    {
        $parent = $this->category('00000000-0000-7000-8000-0000000000c1', WorkspaceFixture::own(), label: 'Alimentation');
        $this->repository->add($parent);
        $this->repository->add($this->category('00000000-0000-7000-8000-0000000000c2', WorkspaceFixture::own()));
        $this->repository->add($this->category(
            '00000000-0000-7000-8000-0000000000c3',
            WorkspaceFixture::own(),
            parentId: $parent->id,
            depth: 2,
        ));
        $this->repository->add($this->category(
            '00000000-0000-7000-8000-0000000000c4',
            WorkspaceFixture::own(),
            type: CategoryType::INCOME,
        ));

        self::assertSame(4, $this->repository->count(WorkspaceFixture::own(), false));
    }

    public function testAParentCannotComeFromAnotherWorkspace(): void
    {
        $foreign = $this->category('00000000-0000-7000-8000-0000000000c1', WorkspaceFixture::other());
        $this->repository->add($foreign);

        $this->expectException(DbalException::class);
        $this->repository->add($this->category(
            '00000000-0000-7000-8000-0000000000c2',
            WorkspaceFixture::own(),
            parentId: $foreign->id,
            depth: 2,
        ));
    }

    public function testTheDatabaseAlsoRejectsDuplicateAnalyticAxes(): void
    {
        $this->expectException(DbalException::class);
        $this->expectExceptionMessageMatches('/analytic axes must be unique/');

        $this->connection->insert('category_categories', [
            'id' => '00000000-0000-7000-8000-0000000000c1',
            'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'type' => 'EXPENSE',
            'label' => 'Restaurants',
            'parent_id' => null,
            'icon' => null,
            'color' => null,
            'default_analytic_axes' => '["ESSENTIAL", "ESSENTIAL"]',
            'budget_included' => true,
            'sort_order' => 0,
            'depth' => 1,
            'version' => 1,
            'created_at' => '2026-09-01 12:00:00+00',
            'updated_at' => '2026-09-01 12:00:00+00',
        ]);
    }

    public function testOptimisticVersioningRejectsAStaleUpdate(): void
    {
        $category = $this->category('00000000-0000-7000-8000-0000000000c1', WorkspaceFixture::own());
        $this->repository->add($category);
        $updated = $category->reconfigure(
            type: $category->type,
            label: 'Sorties',
            icon: $category->icon,
            color: $category->color,
            defaultAnalyticAxes: $category->defaultAnalyticAxes,
            budgetIncluded: $category->budgetIncluded,
            sortOrder: $category->sortOrder,
            updatedAt: new \DateTimeImmutable(),
        );

        self::assertTrue($this->repository->update($updated, 1));
        self::assertFalse($this->repository->update($updated, 1));
        self::assertSame('Sorties', $this->repository->find(WorkspaceFixture::own(), $category->id)?->label);
    }

    public function testChildCreationLockSerializesAConcurrentParentTypeChange(): void
    {
        $parent = $this->category('00000000-0000-7000-8000-0000000000c1', WorkspaceFixture::own());
        $this->repository->add($parent);
        $second = DriverManager::getConnection($this->connection->getParams());

        try {
            $this->connection->beginTransaction();
            self::assertNotNull($this->repository->findForUpdate(WorkspaceFixture::own(), $parent->id));
            $second->beginTransaction();
            $second->executeStatement("SET LOCAL lock_timeout = '100ms'");

            try {
                $second->executeStatement(
                    "UPDATE category_categories SET type = 'INCOME' WHERE workspace_id = ? AND id = ?",
                    [WorkspaceFixture::OWN_WORKSPACE, $parent->id],
                );
                self::fail('The concurrent parent type change should wait for the child-creation lock.');
            } catch (DbalException $exception) {
                self::assertStringContainsString('lock timeout', $exception->getMessage());
            }
        } finally {
            if ($second->isTransactionActive()) {
                $second->rollBack();
            }
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            $second->close();
        }
    }

    public function testParentTypeChangeLockSerializesAConcurrentChildCreation(): void
    {
        $parent = $this->category('00000000-0000-7000-8000-0000000000c1', WorkspaceFixture::own());
        $this->repository->add($parent);
        $second = DriverManager::getConnection($this->connection->getParams());

        try {
            $this->connection->beginTransaction();
            self::assertNotNull($this->repository->findForUpdate(WorkspaceFixture::own(), $parent->id));
            $this->connection->executeStatement(
                "UPDATE category_categories SET type = 'INCOME' WHERE workspace_id = ? AND id = ?",
                [WorkspaceFixture::OWN_WORKSPACE, $parent->id],
            );
            $second->beginTransaction();
            $second->executeStatement("SET LOCAL lock_timeout = '100ms'");

            try {
                $second->insert('category_categories', [
                    'id' => '00000000-0000-7000-8000-0000000000c2',
                    'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
                    'type' => 'EXPENSE',
                    'label' => 'Child',
                    'parent_id' => $parent->id,
                    'icon' => null,
                    'color' => null,
                    'default_analytic_axes' => '[]',
                    'budget_included' => true,
                    'sort_order' => 0,
                    'depth' => 2,
                    'version' => 1,
                    'created_at' => '2026-09-01 12:00:00+00',
                    'updated_at' => '2026-09-01 12:00:00+00',
                ]);
                self::fail('The concurrent child creation should wait for the parent type-change lock.');
            } catch (DbalException $exception) {
                self::assertStringContainsString('lock timeout', $exception->getMessage());
            }
        } finally {
            if ($second->isTransactionActive()) {
                $second->rollBack();
            }
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            $second->close();
        }
    }

    private function category(
        string $id,
        WorkspaceScope $workspace,
        string $label = 'Restaurants',
        ?string $parentId = null,
        int $depth = 1,
        CategoryType $type = CategoryType::EXPENSE,
    ): Category {
        $now = new \DateTimeImmutable('2026-09-01T12:00:00+00:00');

        return new Category(
            id: $id,
            workspace: $workspace,
            type: $type,
            label: $label,
            parentId: $parentId,
            icon: 'utensils',
            color: '#AABBCC',
            defaultAnalyticAxes: [AnalyticAxis::DISCRETIONARY],
            budgetIncluded: true,
            sortOrder: 10,
            depth: $depth,
            version: 1,
            createdAt: $now,
            updatedAt: $now,
        );
    }
}
