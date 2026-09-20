<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Application;

use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Budget\Application\BudgetPlanConflict;
use App\Module\Budget\Application\BudgetPlanNotFound;
use App\Module\Budget\Application\CreateBudgetPlan;
use App\Module\Budget\Application\CreateBudgetPlanInput;
use App\Module\Budget\Application\UpdateBudgetPlan;
use App\Module\Budget\Application\UpdateBudgetPlanInput;
use App\Tests\Module\Budget\Application\Double\CollectingAuditEventRepository;
use App\Tests\Module\Budget\Application\Double\FixedCallerWorkspace;
use App\Tests\Module\Budget\Application\Double\ImmediateTransactionBoundary;
use App\Tests\Module\Budget\Application\Double\InMemoryBudgetPlanRepository;
use App\Tests\Module\Budget\Application\Double\SequenceUuidGenerator;
use App\Tests\Module\Reference\Application\Double\InMemoryAssetCatalog;
use PHPUnit\Framework\TestCase;

final class UpdateBudgetPlanTest extends TestCase
{
    private const string WORKSPACE = '11111111-1111-4111-8111-111111111111';
    private const string OTHER_WORKSPACE = '22222222-2222-4222-8222-222222222222';

    public function testItChangesADraftPeriodAndCurrencyWithOptimisticVersioning(): void
    {
        [$update, $id] = $this->wired();

        $view = $update(new UpdateBudgetPlanInput($id, 1, 'YEAR', '2027', 'USD'));

        self::assertSame('YEAR', $view->periodType);
        self::assertSame('2027', $view->period);
        self::assertSame('USD', $view->assetCode);
        self::assertSame(2, $view->version);
    }

    public function testAStaleEditIsRefused(): void
    {
        [$update, $id] = $this->wired();

        $this->expectException(BudgetPlanConflict::class);

        $update(new UpdateBudgetPlanInput($id, 99, 'MONTH', '2026-10', 'EUR'));
    }

    public function testAPlanFromAnotherWorkspaceIsNotFound(): void
    {
        [, $id, $plans] = $this->wired();
        $audit = new CollectingAuditEventRepository();
        $update = new UpdateBudgetPlan(
            new FixedCallerWorkspace(self::OTHER_WORKSPACE),
            $plans,
            InMemoryAssetCatalog::withCodes('EUR', 'USD'),
            new ImmediateTransactionBoundary(),
            new RecordAuditEvent($audit, new SequenceUuidGenerator()),
        );

        $this->expectException(BudgetPlanNotFound::class);

        $update(new UpdateBudgetPlanInput($id, 1, 'MONTH', '2026-10', 'EUR'));
    }

    /** @return array{UpdateBudgetPlan, string, InMemoryBudgetPlanRepository} */
    private function wired(): array
    {
        $plans = new InMemoryBudgetPlanRepository();
        $caller = new FixedCallerWorkspace(self::WORKSPACE);
        $audit = new CollectingAuditEventRepository();
        $record = new RecordAuditEvent($audit, new SequenceUuidGenerator());
        $assets = InMemoryAssetCatalog::withCodes('EUR', 'USD');
        $create = new CreateBudgetPlan($caller, $plans, $assets, new SequenceUuidGenerator(), new ImmediateTransactionBoundary(), $record);
        $plan = $create(new CreateBudgetPlanInput('MONTH', '2026-09', 'EUR'));

        return [new UpdateBudgetPlan($caller, $plans, $assets, new ImmediateTransactionBoundary(), $record), $plan->id, $plans];
    }
}
