<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Accounts\Application\ListPortfolioAccounts;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Portfolio\Domain\Cash\CashLedger;
use App\Module\Portfolio\Domain\PortfolioOperationRepository;

final readonly class ListPortfolios
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private ListPortfolioAccounts $accounts,
        private WorkspaceCalendar $calendar,
        private PortfolioOverviewQuery $query,
        private PortfolioOperationRepository $operations,
        private ReconcilePortfolioPositions $positions,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        $workspace = $this->caller->resolveContext()->workspace;
        $accounts = ($this->accounts)($workspace);
        $today = $this->calendar->today();
        $items = [];
        foreach (array_slice($accounts, 0, 50) as $account) {
            [, , $view] = ($this->query)($account->id, null);
            $count = 0;
            foreach (($this->positions)($workspace, $account->id, $today)->positions as $position) {
                if (!$position->quantity->isZero()) {
                    ++$count;
                }
            }
            $items[] = ['accountId' => $account->id, 'label' => $account->label, 'institution' => $account->institution,
                'productCode' => $account->productCode, 'assetCode' => $account->assetCode, 'status' => $account->status,
                'latestValuation' => PortfolioOverviewQuery::valuation($view, $today),
                'currentCash' => CashLedger::replay([], $this->operations->activeForAccount($workspace, $account->id, $today))->closingBalance->toString(),
                'positionCount' => $count];
        }

        return ['items' => $items, 'truncated' => count($accounts) > 50];
    }
}
