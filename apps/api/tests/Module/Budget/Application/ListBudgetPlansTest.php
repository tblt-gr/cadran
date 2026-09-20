<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Application;

use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Budget\Application\CreateBudgetPlan;
use App\Module\Budget\Application\CreateBudgetPlanInput;
use App\Module\Budget\Application\ListBudgetPlans;
use App\Tests\Module\Budget\Application\Double\CollectingAuditEventRepository;
use App\Tests\Module\Budget\Application\Double\FixedCallerWorkspace;
use App\Tests\Module\Budget\Application\Double\ImmediateTransactionBoundary;
use App\Tests\Module\Budget\Application\Double\InMemoryBudgetPlanRepository;
use App\Tests\Module\Budget\Application\Double\SequenceUuidGenerator;
use App\Tests\Module\Reference\Application\Double\InMemoryAssetCatalog;
use PHPUnit\Framework\TestCase;

final class ListBudgetPlansTest extends TestCase
{
    private const string WORKSPACE = '11111111-1111-4111-8111-111111111111';
    private const string OTHER_WORKSPACE = '22222222-2222-4222-8222-222222222222';

    public function testItListsOnlyTheCallersWorkspacePlans(): void
    {
        $plans = new InMemoryBudgetPlanRepository();
        $audit = new CollectingAuditEventRepository();
        $ownCaller = new FixedCallerWorkspace(self::WORKSPACE);
        $otherCaller = new FixedCallerWorkspace(self::OTHER_WORKSPACE);
        $uuids = new SequenceUuidGenerator();
        $assets = InMemoryAssetCatalog::withCodes('EUR');

        $createOwn = new CreateBudgetPlan($ownCaller, $plans, $assets, $uuids, new ImmediateTransactionBoundary(), new RecordAuditEvent($audit, new SequenceUuidGenerator()));
        ($createOwn)(new CreateBudgetPlanInput('MONTH', '2026-09', 'EUR'));

        $createOther = new CreateBudgetPlan($otherCaller, $plans, $assets, $uuids, new ImmediateTransactionBoundary(), new RecordAuditEvent($audit, new SequenceUuidGenerator()));
        ($createOther)(new CreateBudgetPlanInput('MONTH', '2026-09', 'EUR'));

        $list = new ListBudgetPlans($ownCaller, $plans);
        $page = ($list)(1, 50);

        self::assertCount(1, $page->items);
        self::assertSame(1, $page->total);
    }

    public function testItPaginates(): void
    {
        $plans = new InMemoryBudgetPlanRepository();
        $audit = new CollectingAuditEventRepository();
        $caller = new FixedCallerWorkspace(self::WORKSPACE);
        $create = new CreateBudgetPlan($caller, $plans, InMemoryAssetCatalog::withCodes('EUR'), new SequenceUuidGenerator(), new ImmediateTransactionBoundary(), new RecordAuditEvent($audit, new SequenceUuidGenerator()));
        ($create)(new CreateBudgetPlanInput('MONTH', '2026-09', 'EUR'));
        ($create)(new CreateBudgetPlanInput('YEAR', '2026', 'EUR'));

        $list = new ListBudgetPlans($caller, $plans);
        $page = ($list)(1, 1);

        self::assertCount(1, $page->items);
        self::assertSame(2, $page->total);
        self::assertSame(1, $page->page);
        self::assertSame(1, $page->perPage);
    }
}
