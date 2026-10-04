<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Accounts\Application\AccountView;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Portfolio\Domain\Cash\CashLedger;
use App\Module\Portfolio\Domain\OperationType;
use App\Module\Portfolio\Domain\PortfolioOperation;
use App\Module\Portfolio\Domain\PortfolioOperationRepository;

final readonly class ReadPortfolioSummary
{
    public function __construct(
        private PortfolioOverviewQuery $query,
        private PortfolioOperationRepository $operations,
        private ReadPortfolioReconciliation $reconcile,
        private PortfolioDueMonths $dueMonths,
        private CallerWorkspaceContext $caller,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(string $accountId, ?string $asOfLiteral): array
    {
        [$account, $asOf, $view, $newerPartial] = ($this->query)($accountId, $asOfLiteral);
        $operations = $asOf < $account->openedOn ? [] : $this->operations->activeForAccount($account->workspace, $accountId, $asOf);
        $from = $asOf->setDate((int) $asOf->format('Y'), 1, 1)->format('Y-m-d');
        $to = $asOf->format('Y-m-d');
        $range = array_values(array_filter($operations, static fn (PortfolioOperation $operation): bool => $operation->details->tradeDate->format('Y-m-d') >= $from));
        $cash = CashLedger::replay([], $operations)->closingBalance;
        $totals = CashLedger::replay([], $range)->totals;
        $valuation = PortfolioOverviewQuery::valuation($view, $asOf);
        $partialMetadata = PortfolioOverviewQuery::valuation($newerPartial, $asOf);
        $gain = PortfolioUnrealizedGain::of($view);
        $warnings = ($this->reconcile)($account, $asOf, $operations, $view, $newerPartial);
        $status = [] === $warnings ? 'RECONCILED' : 'NEEDS_REVIEW';
        $valuationSources = null === $valuation ? [] : [['kind' => 'VALUATION', 'id' => $valuation['id'], 'date' => $valuation['valuedOn']]];
        $partialSources = null === $partialMetadata ? [] : [['kind' => 'VALUATION', 'id' => $partialMetadata['id'], 'date' => $partialMetadata['valuedOn']]];
        $valuationReason = match (true) {
            null === $valuation => 'NO_VALUATION',
            'PARTIAL' === $valuation['completeness'] => 'PARTIAL_VALUATION',
            $valuation['stale'] => 'STALE_VALUATION',
            default => null,
        };
        $valuationQuality = $valuationReason ?? 'CURRENT';
        $periodOn = $valuation['valuedOn'] ?? $to;
        $allSources = PortfolioExplanation::operations($operations);
        $frozenSources = PortfolioExplanation::operations(array_values(array_filter($operations,
            static fn (PortfolioOperation $operation): bool => $operation->details->tradeDate->format('Y-m-d') <= $periodOn)));

        $deposits = array_values(array_filter($range, static fn (PortfolioOperation $operation): bool => in_array($operation->details->type, [OperationType::DEPOSIT, OperationType::WITHDRAWAL], true)));
        $costs = array_values(array_filter($range, static fn (PortfolioOperation $operation): bool => (null !== $operation->details->fees && !ExactDecimal::isZero($operation->details->fees)) || (null !== $operation->details->taxes && !ExactDecimal::isZero($operation->details->taxes))));
        $accountView = AccountView::fromAccount($account);
        $workspace = $this->caller->resolveContext()->workspace;
        $dueMonths = ($this->dueMonths)($workspace, [$accountView], $this->dueMonths->today($workspace))[$accountId] ?? [];

        return [
            'asOf' => $to,
            'account' => ['id' => $accountId, 'label' => $account->label, 'institution' => $account->institution,
                'productCode' => $accountView->productCode, 'assetCode' => $accountView->assetCode, 'status' => $accountView->status, 'openedOn' => $accountView->openedOn],
            'dueMonths' => $dueMonths,
            'valuation' => $valuation,
            'newerPartialValuation' => $partialMetadata,
            'totalValue' => $view?->valuation->totalValue?->toString(),
            'cash' => $cash->toString(),
            'unrealizedGain' => $gain,
            'netDeposits' => ['value' => ExactDecimal::subtract($totals->deposits, $totals->withdrawals)->toString(),
                'deposits' => $totals->deposits->toString(), 'withdrawals' => $totals->withdrawals->toString(), 'from' => $from, 'to' => $to],
            'costs' => ['fees' => $totals->fees->toString(), 'taxes' => $totals->taxes->toString(),
                'total' => ExactDecimal::add($totals->fees, $totals->taxes)->toString(), 'from' => $from, 'to' => $to],
            'reconciliation' => ['status' => $status, 'warnings' => $warnings],
            'explanations' => [
                'TOTAL_VALUE' => PortfolioExplanation::of($periodOn, $periodOn, $valuationQuality, $valuationReason, $valuationSources),
                'UNREALIZED_GAIN' => PortfolioExplanation::of($periodOn, $periodOn, $gain['reason'] ?? $valuationQuality, $gain['reason'] ?? $valuationReason, [...$valuationSources, ...$frozenSources]),
                'CASH' => PortfolioExplanation::of($accountView->openedOn, $to, 'DERIVED', null, $allSources),
                'NET_DEPOSITS' => PortfolioExplanation::of($from, $to, 'DERIVED', null, PortfolioExplanation::operations($deposits)),
                'COSTS' => PortfolioExplanation::of($from, $to, 'DERIVED', null, PortfolioExplanation::operations($costs)),
                'RECONCILIATION' => PortfolioExplanation::of($accountView->openedOn, $to, $status, null, [...$valuationSources, ...$partialSources, ...$allSources]),
            ],
        ];
    }
}
