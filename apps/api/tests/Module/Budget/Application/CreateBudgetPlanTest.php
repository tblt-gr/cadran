<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Application;

use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Budget\Application\BudgetAuditEvents;
use App\Module\Budget\Application\CreateBudgetPlan;
use App\Module\Budget\Application\CreateBudgetPlanInput;
use App\Module\Budget\Application\InvalidBudgetPlanInput;
use App\Tests\Module\Budget\Application\Double\CollectingAuditEventRepository;
use App\Tests\Module\Budget\Application\Double\FixedCallerWorkspace;
use App\Tests\Module\Budget\Application\Double\ImmediateTransactionBoundary;
use App\Tests\Module\Budget\Application\Double\InMemoryBudgetPlanRepository;
use App\Tests\Module\Budget\Application\Double\SequenceUuidGenerator;
use App\Tests\Module\Reference\Application\Double\InMemoryAssetCatalog;
use PHPUnit\Framework\TestCase;

final class CreateBudgetPlanTest extends TestCase
{
    private const string WORKSPACE = '11111111-1111-4111-8111-111111111111';
    private const string OTHER_WORKSPACE = '22222222-2222-4222-8222-222222222222';

    public function testItCreatesADraftMonthlyPlanInTheCallerWorkspace(): void
    {
        [$useCase, $plans, $audit] = $this->wired();

        $view = ($useCase)(new CreateBudgetPlanInput('MONTH', '2026-09', 'EUR'));

        self::assertSame('DRAFT', $view->state);
        self::assertSame('MONTH', $view->periodType);
        self::assertSame('2026-09', $view->period);
        self::assertSame('EUR', $view->assetCode);
        self::assertSame(1, $view->version);
        self::assertNotNull($plans->find(\App\Module\Foundation\Domain\WorkspaceScope::fromString(self::WORKSPACE), $view->id));
        self::assertCount(1, $audit->events);
        self::assertSame(BudgetAuditEvents::PLAN_CREATED, $audit->events[0]->eventType);
    }

    public function testItCreatesAnAnnualPlan(): void
    {
        [$useCase] = $this->wired();

        $view = ($useCase)(new CreateBudgetPlanInput('YEAR', '2026', 'EUR'));

        self::assertSame('YEAR', $view->periodType);
        self::assertSame('2026', $view->period);
    }

    public function testSeveralDraftPlansMayBePreparedForTheSamePeriod(): void
    {
        [$useCase, $plans] = $this->wired();
        ($useCase)(new CreateBudgetPlanInput('MONTH', '2026-09', 'EUR'));
        ($useCase)(new CreateBudgetPlanInput('MONTH', '2026-09', 'EUR'));

        self::assertSame(2, $plans->count(\App\Module\Foundation\Domain\WorkspaceScope::fromString(self::WORKSPACE)));
    }

    public function testTheSamePeriodInAnotherWorkspaceIsAllowed(): void
    {
        [$useCase] = $this->wired(self::WORKSPACE);
        ($useCase)(new CreateBudgetPlanInput('MONTH', '2026-09', 'EUR'));

        [$otherUseCase] = $this->wired(self::OTHER_WORKSPACE);
        $view = ($otherUseCase)(new CreateBudgetPlanInput('MONTH', '2026-09', 'EUR'));

        self::assertSame('DRAFT', $view->state);
    }

    public function testAnInvalidRangeIsRefused(): void
    {
        [$useCase] = $this->wired();

        $this->expectException(InvalidBudgetPlanInput::class);

        ($useCase)(new CreateBudgetPlanInput('MONTH', '2026-13', 'EUR'));
    }

    public function testAnUnknownPeriodTypeIsRefused(): void
    {
        [$useCase] = $this->wired();

        $this->expectException(InvalidBudgetPlanInput::class);

        ($useCase)(new CreateBudgetPlanInput('WEEK', '2026-09', 'EUR'));
    }

    public function testAnUnknownAssetIsRefused(): void
    {
        [$useCase] = $this->wired();

        $this->expectException(InvalidBudgetPlanInput::class);

        ($useCase)(new CreateBudgetPlanInput('MONTH', '2026-09', 'ZZZ'));
    }

    public function testACryptoAssetIsRefused(): void
    {
        [$useCase] = $this->wired();

        $this->expectException(InvalidBudgetPlanInput::class);

        ($useCase)(new CreateBudgetPlanInput('MONTH', '2026-09', 'BTC'));
    }

    /**
     * @return array{CreateBudgetPlan, InMemoryBudgetPlanRepository, CollectingAuditEventRepository}
     */
    private function wired(string $workspace = self::WORKSPACE): array
    {
        $plans = new InMemoryBudgetPlanRepository();
        $audit = new CollectingAuditEventRepository();
        $caller = new FixedCallerWorkspace($workspace);
        $useCase = new CreateBudgetPlan(
            $caller,
            $plans,
            InMemoryAssetCatalog::withCodes('EUR')->andCryptoCodes('BTC'),
            new SequenceUuidGenerator(),
            new ImmediateTransactionBoundary(),
            new RecordAuditEvent($audit, new SequenceUuidGenerator()),
        );

        return [$useCase, $plans, $audit];
    }
}
