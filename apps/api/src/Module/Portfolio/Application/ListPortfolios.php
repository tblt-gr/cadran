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
    public const int MAX_ACCOUNTS = 50;

    public function __construct(
        private CallerWorkspaceContext $caller,
        private ListPortfolioAccounts $accounts,
        private WorkspaceCalendar $calendar,
        private PortfolioOverviewQuery $query,
        private PortfolioOperationRepository $operations,
        private ReconcilePortfolioPositions $positions,
        private PortfolioDueMonths $dueMonths,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        $workspace = $this->caller->resolveContext()->workspace;
        $accounts = ($this->accounts)($workspace);
        $today = $this->calendar->today();
        $shown = array_slice($accounts, 0, self::MAX_ACCOUNTS);
        $due = ($this->dueMonths)($workspace, $shown, $today);
        $items = [];
        $figures = [];
        foreach ($shown as $account) {
            [, , $view, $newerPartial] = ($this->query)($account->id, null);
            $count = 0;
            foreach (($this->positions)($workspace, $account->id, $today)->positions as $position) {
                if (!$position->quantity->isZero()) {
                    ++$count;
                }
            }
            $gain = PortfolioUnrealizedGain::of($view);
            $figures[] = ['assetCode' => $account->assetCode, 'totalValue' => $view?->valuation->totalValue?->toString(),
                'gain' => $gain['value'], 'costBasis' => $gain['costBasis'],
                'valuedOn' => $view?->valuation->valuedOn->format('Y-m-d'), 'newerPartial' => null !== $newerPartial];
            $items[] = ['accountId' => $account->id, 'label' => $account->label, 'institution' => $account->institution,
                'productCode' => $account->productCode, 'assetCode' => $account->assetCode, 'status' => $account->status,
                'latestValuation' => PortfolioOverviewQuery::valuation($view, $today),
                'newerPartialValuation' => PortfolioOverviewQuery::valuation($newerPartial, $today),
                'currentCash' => CashLedger::replay([], $this->operations->activeForAccount($workspace, $account->id, $today))->closingBalance->toString(),
                'positionCount' => $count, 'unrealizedGain' => ['value' => $gain['value'], 'costBasis' => $gain['costBasis'],
                    'performance' => PortfolioUnrealizedGain::performance($gain['value'], $gain['costBasis']), 'reason' => $gain['reason']],
                'dueMonths' => $due[$account->id] ?? []];
        }

        return ['items' => $items, 'total' => PortfolioTotal::of($figures), 'truncated' => count($accounts) > self::MAX_ACCOUNTS];
    }
}
