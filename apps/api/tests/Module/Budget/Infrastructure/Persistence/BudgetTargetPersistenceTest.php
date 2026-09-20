<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Infrastructure\Persistence;

use App\Module\Budget\Domain\BudgetPeriod;
use App\Module\Budget\Domain\BudgetPlan;
use App\Module\Budget\Domain\BudgetPlanState;
use App\Module\Budget\Domain\BudgetScopeType;
use App\Module\Budget\Domain\BudgetTarget;
use App\Module\Budget\Domain\BudgetValueType;
use App\Module\Budget\Infrastructure\Persistence\DbalBudgetPlanRepository;
use App\Module\Budget\Infrastructure\Persistence\DbalBudgetTargetRepository;
use App\Module\Categories\Domain\Category;
use App\Module\Categories\Domain\CategoryType;
use App\Module\Categories\Infrastructure\Persistence\DbalCategoryRepository;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BudgetTargetPersistenceTest extends KernelTestCase
{
    private const string PLAN_ID = '00000000-0000-7000-8000-0000000000e1';
    private const string CATEGORY_ID = '00000000-0000-7000-8000-0000000000e2';

    private WorkspaceFixture $fixture;
    private DbalBudgetTargetRepository $repository;
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->fixture = new WorkspaceFixture($connection);
        $this->repository = new DbalBudgetTargetRepository($connection);
        $this->databaseReady = true;
        $this->fixture->reset();
        $this->fixture->seed();

        (new DbalBudgetPlanRepository($connection))->add($this->plan(WorkspaceFixture::own()));
        (new DbalCategoryRepository($connection))->add($this->category(WorkspaceFixture::own()));
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            $this->fixture->reset();
        }
        parent::tearDown();
    }

    public function testAnAmountTargetRoundTripsItsExactCanonicalDecimal(): void
    {
        // Hand-computed: "300.00" must come back as "300.00", not "300" nor
        // "300.000000000000000000000000" — the stored scale is what restores it.
        $target = $this->target('00000000-0000-7000-8000-0000000000e3', WorkspaceFixture::own(), BudgetValueType::AMOUNT, DecimalValue::fromString('300.00'), null);
        $this->repository->add($target);

        $found = $this->repository->find(WorkspaceFixture::own(), $target->id);

        self::assertNotNull($found);
        self::assertSame('300.00', $found->amount?->toString());
        self::assertNull($found->ratio);
    }

    public function testARatioTargetRoundTripsItsExactCanonicalDecimal(): void
    {
        $target = $this->target('00000000-0000-7000-8000-0000000000e3', WorkspaceFixture::own(), BudgetValueType::RATIO, null, DecimalValue::fromString('0.30'));
        $this->repository->add($target);

        $found = $this->repository->find(WorkspaceFixture::own(), $target->id);

        self::assertNotNull($found);
        self::assertSame('0.30', $found->ratio?->toString());
        self::assertNull($found->amount);
    }

    public function testATargetIsInvisibleFromAnotherWorkspace(): void
    {
        $this->repository->add($this->target('00000000-0000-7000-8000-0000000000e3', WorkspaceFixture::own(), BudgetValueType::AMOUNT, DecimalValue::fromString('300.00'), null));

        self::assertNull($this->repository->find(WorkspaceFixture::other(), '00000000-0000-7000-8000-0000000000e3'));
    }

    public function testListByPlanReturnsOnlyThatPlansTargets(): void
    {
        $this->repository->add($this->target('00000000-0000-7000-8000-0000000000e3', WorkspaceFixture::own(), BudgetValueType::AMOUNT, DecimalValue::fromString('300.00'), null));

        self::assertCount(1, $this->repository->listByPlan(WorkspaceFixture::own(), self::PLAN_ID, 10));
        self::assertSame(0, $this->repository->countByPlan(WorkspaceFixture::own(), '00000000-0000-7000-8000-000000009999'));
    }

    public function testAConcurrentUpdateOnAStaleVersionIsRefused(): void
    {
        $target = $this->target('00000000-0000-7000-8000-0000000000e3', WorkspaceFixture::own(), BudgetValueType::AMOUNT, DecimalValue::fromString('300.00'), null);
        $this->repository->add($target);

        $renamed = new BudgetTarget(
            id: $target->id,
            workspace: $target->workspace,
            planId: $target->planId,
            scopeType: $target->scopeType,
            scopeId: $target->scopeId,
            valueType: BudgetValueType::AMOUNT,
            amount: DecimalValue::fromString('450.00'),
            ratio: null,
            version: 2,
            createdAt: $target->createdAt,
            updatedAt: new \DateTimeImmutable(),
        );

        self::assertTrue($this->repository->update($renamed, 1));
        self::assertFalse($this->repository->update($renamed, 1));
    }

    private function plan(WorkspaceScope $workspace): BudgetPlan
    {
        $now = new \DateTimeImmutable('2026-09-01T00:00:00+00:00');

        return new BudgetPlan(
            id: self::PLAN_ID,
            workspace: $workspace,
            period: BudgetPeriod::month(2026, 9),
            assetCode: AssetCode::fromString('EUR'),
            state: BudgetPlanState::DRAFT,
            version: 1,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    private function category(WorkspaceScope $workspace): Category
    {
        $now = new \DateTimeImmutable('2026-09-01T00:00:00+00:00');

        return new Category(
            id: self::CATEGORY_ID,
            workspace: $workspace,
            type: CategoryType::EXPENSE,
            label: 'Groceries',
            parentId: null,
            icon: null,
            color: null,
            defaultAnalyticAxes: [],
            budgetIncluded: true,
            sortOrder: 0,
            depth: 1,
            version: 1,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    private function target(string $id, WorkspaceScope $workspace, BudgetValueType $valueType, ?DecimalValue $amount, ?DecimalValue $ratio): BudgetTarget
    {
        $now = new \DateTimeImmutable('2026-09-01T00:00:00+00:00');

        return new BudgetTarget(
            id: $id,
            workspace: $workspace,
            planId: self::PLAN_ID,
            scopeType: BudgetScopeType::CATEGORY,
            scopeId: self::CATEGORY_ID,
            valueType: $valueType,
            amount: $amount,
            ratio: $ratio,
            version: 1,
            createdAt: $now,
            updatedAt: $now,
        );
    }
}
