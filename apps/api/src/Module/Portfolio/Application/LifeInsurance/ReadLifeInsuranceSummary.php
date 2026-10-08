<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\LifeInsurance;

use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Portfolio\Application\Performance\ComputePortfolioPerformance;
use App\Module\Portfolio\Application\Performance\PerformancePeriodBuilder;
use App\Module\Portfolio\Application\PortfolioAccountGate;
use App\Module\Portfolio\Application\PortfolioOverviewQuery;
use App\Module\Portfolio\Application\ValuationResolver;
use App\Module\Portfolio\Domain\InvalidPortfolioOperation;
use App\Module\Portfolio\Domain\LifeInsurance\ContractAllocation;
use App\Module\Portfolio\Domain\LifeInsurance\EuroFundRateRepository;
use App\Module\Portfolio\Domain\LifeInsurance\LifeInsuranceContractRepository;
use App\Module\Portfolio\Domain\Performance\PerformancePeriod;
use App\Module\Portfolio\Domain\PortfolioOperation;
use App\Module\Portfolio\Domain\Valuation\PriceQuality;
use App\Module\Portfolio\Domain\Valuation\ValuationCompleteness;

/** Read-only composition of recorded observations, existing ledger totals and PERF-001. */
final readonly class ReadLifeInsuranceSummary
{
    public function __construct(private CallerWorkspaceContext $caller, private PortfolioAccountGate $gate,
        private PortfolioOverviewQuery $query, private LifeInsuranceContractRepository $contracts,
        private EuroFundRateRepository $rates, private ReadLifeInsuranceIncomeAndFees $income,
        private PerformancePeriodBuilder $periods, private ComputePortfolioPerformance $performance)
    {
    }

    /** @return array<string, mixed> */
    public function __invoke(string $accountId, ?string $literal): array
    {
        $workspace = $this->caller->resolveContext()->workspace;
        $this->gate->forLifeInsurance($workspace, $accountId, false);
        try {
            if (null !== $literal) {
                ValuationResolver::parseDay($literal, 'invalid_summary_date');
            }
            [$account, $asOf, $view] = ($this->query)($accountId, $literal);
        } catch (InvalidPortfolioOperation $exception) {
            if (in_array($exception->problemCode, ['invalid_as_of', 'invalid_summary_date'], true)) {
                throw new InvalidPortfolioOperation('A valid non-future summary date is required.', 'invalid_summary_date');
            }
            throw $exception;
        }
        $contract = $this->contracts->findByAccount($workspace, $accountId);
        $history = array_values(array_filter($this->periods->history($workspace, $account), static fn (PortfolioOperation $operation): bool => $operation->details->tradeDate <= $asOf));
        $firstYear = (int) ($contract->taxStartOn ?? $history[0]->details->tradeDate ?? $asOf)->format('Y');
        if ([] !== $history) {
            $firstYear = min($firstYear, (int) $history[0]->details->tradeDate->format('Y'));
        }
        $lastYear = (int) $asOf->format('Y');
        if ($lastYear - $firstYear + 1 > 60) {
            throw new InvalidPortfolioOperation('The summary covers at most 60 calendar years.', 'invalid_summary_date');
        }
        $rates = null === $contract ? [] : $this->rates->listForContract($workspace, $contract->id);
        $years = [];
        $annual = [];
        $cumulativeYears = [];
        $cumulativeAnnual = [];
        $start = $contract->subscribedOn ?? $account->openedOn;
        for ($year = $firstYear; $year <= $lastYear; ++$year) {
            $operations = array_values(array_filter($history, static fn (PortfolioOperation $operation): bool => (int) $operation->details->tradeDate->format('Y') === $year));
            $report = $this->income->summarize($year, $account->assetCode->toString(), $operations, $rates);
            $annual[] = $report;
            $years[] = SummaryCumulative::taxYear($year, $operations, $report);
            $sinceStart = array_values(array_filter($operations, static fn (PortfolioOperation $operation): bool => $operation->details->tradeDate >= $start));
            $cumulativeReport = count($sinceStart) === count($operations) ? $report : $this->income->summarize($year, $account->assetCode->toString(), $sinceStart, $rates);
            $cumulativeYears[] = SummaryCumulative::taxYear($year, $sinceStart, $cumulativeReport);
            $cumulativeAnnual[] = $cumulativeReport;
        }
        $cumulative = SummaryCumulative::of($cumulativeYears, $cumulativeAnnual);
        if (null !== $view && ValuationCompleteness::COMPLETE !== $view->valuation->completeness) {
            $view = null;
        }
        $valuation = PortfolioOverviewQuery::valuation($view, $asOf);
        $supportData = null === $view ? null : SummarySupports::of($view, $rates, $history, $asOf);
        $status = null === $view ? 'NO_VALUATION' : ('TOTAL' === $view->valuation->detail->value ? 'NOT_DETAILED' : 'AVAILABLE');
        $items = [];
        if (null !== $valuation && null !== $view && null !== $supportData) {
            $observed = $view->valuation;
            $valuation['stale'] = $valuation['stale'] || PriceQuality::STALE === $observed->priceQuality();
            $valuation += ['cash' => $observed->cash->toString(), 'euroFundValue' => 'AVAILABLE' === $status ? $supportData['euroFundValue'] : null,
                'unitLinkedValue' => 'AVAILABLE' === $status ? $supportData['unitLinkedValue'] : null,
                'observedTotal' => $observed->observedTotal?->toString(), 'observedTotalDifference' => $observed->observedTotalDifference()?->toString(),
                'tolerance' => $observed->tolerance($view->displayPrecision)->toString(), 'flags' => array_map(static fn ($flag): string => $flag->value, $view->flags)];
            if ('AVAILABLE' === $status) {
                $items = ContractAllocation::of($observed->totalValue?->toString() ?? throw new \LogicException('Complete valuation has a total.'),
                    $observed->cash->toString(), $supportData['euroFundValue'], $supportData['unitLinkedValue']);
            }
        }
        $issues = SummaryCumulative::issues($annual);
        foreach ([$status => 'AVAILABLE' === $status ? 0 : 1, 'STALE_VALUATION' => ($valuation['stale'] ?? false) ? 1 : 0,
            'RATE_NOT_UPDATED' => $supportData['missingRates'] ?? 0] as $code => $count) {
            if ($count > 0) {
                $issues[] = ['code' => $code, 'operationIds' => [], 'count' => $count];
            }
        }
        $age = null === $contract || $asOf < $contract->taxStartOn ? null : $contract->taxStartOn->diff($asOf);

        return ['asOf' => $asOf->format('Y-m-d'), 'assetCode' => $account->assetCode->toString(), 'contract' => $contract,
            'valuation' => $valuation, 'allocation' => ['status' => $status, 'items' => $items], 'cumulative' => $cumulative,
            'gainSinceOpening' => ($this->performance)($workspace, $account, PerformancePeriod::of('INCEPTION', null, $asOf, $account->openedOn), $asOf, $history),
            'supports' => $supportData['supports'] ?? [], 'taxYears' => $years,
            'contractAge' => null === $age ? null : ['taxStartOn' => $contract->taxStartOn->format('Y-m-d'), 'years' => $age->y, 'months' => $age->m],
            'taxEstimate' => ['status' => 'NO_RULE_SET', 'ruleSetId' => null], 'qualityIssues' => $issues];
    }
}
