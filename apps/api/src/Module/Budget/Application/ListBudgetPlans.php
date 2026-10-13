<?php

declare(strict_types=1);

namespace App\Module\Budget\Application;

use App\Module\Budget\Domain\BudgetPlanRepository;
use App\Module\Foundation\Application\CallerWorkspace;

final readonly class ListBudgetPlans
{
    public function __construct(
        private CallerWorkspace $caller,
        private BudgetPlanRepository $plans,
    ) {
    }

    public function __invoke(int $page, int $pageSize): BudgetPlanPage
    {
        $workspace = $this->caller->resolve();
        $present = new PresentBudgetPlan();

        $plans = $this->plans->list($workspace, $pageSize, ($page - 1) * $pageSize);

        return new BudgetPlanPage(
            items: array_map(static fn ($plan) => $present($plan), $plans),
            page: $page,
            pageSize: $pageSize,
            total: $this->plans->count($workspace),
        );
    }
}
