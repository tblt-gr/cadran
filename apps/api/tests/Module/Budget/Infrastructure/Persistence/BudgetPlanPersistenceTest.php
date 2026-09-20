<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Infrastructure\Persistence;

use App\Module\Budget\Domain\BudgetPeriod;
use App\Module\Budget\Domain\BudgetPlan;
use App\Module\Budget\Domain\BudgetPlanState;
use App\Module\Budget\Infrastructure\Persistence\DbalBudgetPlanRepository;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BudgetPlanPersistenceTest extends KernelTestCase
{
    private WorkspaceFixture $fixture;
    private DbalBudgetPlanRepository $repository;
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->fixture = new WorkspaceFixture($connection);
        $this->repository = new DbalBudgetPlanRepository($connection);
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

    public function testAPlanRoundTripsExactlyThroughThePeriodAndVersion(): void
    {
        $plan = $this->plan('00000000-0000-7000-8000-0000000000e1', WorkspaceFixture::own());
        $this->repository->add($plan);

        $found = $this->repository->find(WorkspaceFixture::own(), $plan->id);

        self::assertNotNull($found);
        self::assertSame('2026-09', $found->period->key());
        self::assertSame(BudgetPlanState::DRAFT, $found->state);
        self::assertSame(1, $found->version);
    }

    public function testAPlanIsInvisibleFromAnotherWorkspace(): void
    {
        $this->repository->add($this->plan('00000000-0000-7000-8000-0000000000e1', WorkspaceFixture::own()));

        self::assertNull($this->repository->find(WorkspaceFixture::other(), '00000000-0000-7000-8000-0000000000e1'));
    }

    public function testSeveralDraftPlansForTheSamePeriodAreAllowed(): void
    {
        $this->repository->add($this->plan('00000000-0000-7000-8000-0000000000e1', WorkspaceFixture::own()));
        $this->repository->add($this->plan('00000000-0000-7000-8000-0000000000e2', WorkspaceFixture::own()));

        self::assertSame(2, $this->repository->count(WorkspaceFixture::own()));
    }

    public function testTheSamePeriodInTwoWorkspacesIsAllowed(): void
    {
        $this->repository->add($this->plan('00000000-0000-7000-8000-0000000000e1', WorkspaceFixture::own()));
        $this->repository->add($this->plan('00000000-0000-7000-8000-0000000000e2', WorkspaceFixture::other()));

        self::assertNotNull($this->repository->find(WorkspaceFixture::own(), '00000000-0000-7000-8000-0000000000e1'));
        self::assertNotNull($this->repository->find(WorkspaceFixture::other(), '00000000-0000-7000-8000-0000000000e2'));
    }

    public function testAConcurrentUpdateOnAStaleVersionIsRefused(): void
    {
        $plan = $this->plan('00000000-0000-7000-8000-0000000000e1', WorkspaceFixture::own());
        $this->repository->add($plan);

        $activated = $plan->activate(new \DateTimeImmutable());
        self::assertTrue($this->repository->update($activated, 1));

        // A second editor still holding version 1 loses the race.
        self::assertFalse($this->repository->update($plan->activate(new \DateTimeImmutable()), 1));
    }

    private function plan(string $id, WorkspaceScope $workspace): BudgetPlan
    {
        $now = new \DateTimeImmutable('2026-09-01T00:00:00+00:00');

        return new BudgetPlan(
            id: $id,
            workspace: $workspace,
            period: BudgetPeriod::month(2026, 9),
            assetCode: AssetCode::fromString('EUR'),
            state: BudgetPlanState::DRAFT,
            version: 1,
            createdAt: $now,
            updatedAt: $now,
        );
    }
}
