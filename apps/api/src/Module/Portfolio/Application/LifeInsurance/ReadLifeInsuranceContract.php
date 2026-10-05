<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\LifeInsurance;

use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Portfolio\Application\InstrumentView;
use App\Module\Portfolio\Application\PortfolioAccountGate;
use App\Module\Portfolio\Application\ValuationHoldings;
use App\Module\Portfolio\Domain\InstrumentRepository;
use App\Module\Portfolio\Domain\InstrumentType;
use App\Module\Portfolio\Domain\LifeInsurance\EuroFundRate;
use App\Module\Portfolio\Domain\LifeInsurance\EuroFundRateRepository;
use App\Module\Portfolio\Domain\LifeInsurance\FeeTermsRepository;
use App\Module\Portfolio\Domain\LifeInsurance\LifeInsuranceContractRepository;

/** The contract of a life-insurance account with its fee terms, announced euro-fund rates and rate freshness. */
final readonly class ReadLifeInsuranceContract
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private PortfolioAccountGate $gate,
        private LifeInsuranceContractRepository $contracts,
        private FeeTermsRepository $feeTerms,
        private EuroFundRateRepository $rates,
        private InstrumentRepository $instruments,
        private ValuationHoldings $holdings,
        private WorkspaceCalendar $calendar,
    ) {
    }

    public function __invoke(string $accountId): LifeInsuranceOverview
    {
        $workspace = $this->caller->resolveContext()->workspace;
        $account = $this->gate->forLifeInsurance($workspace, $accountId, false);
        $contract = $this->contracts->findByAccount($workspace, $account->id);
        if (null === $contract) {
            return new LifeInsuranceOverview($account->assetCode->toString(), null, [], [], []);
        }
        $rates = $this->rates->listForContract($workspace, $contract->id);
        $today = $this->calendar->today();

        return new LifeInsuranceOverview(
            $account->assetCode->toString(),
            $contract,
            $this->feeTerms->listForContract($workspace, $contract->id),
            $rates,
            $this->euroFunds($account->id, $rates, $today),
        );
    }

    /**
     * @param list<EuroFundRate> $rates
     *
     * @return list<EuroFundState>
     */
    private function euroFunds(string $accountId, array $rates, \DateTimeImmutable $today): array
    {
        $workspace = $this->caller->resolveContext()->workspace;
        [, $held] = $this->holdings->held($workspace, $accountId, $today);
        $ids = array_keys($held);
        $latest = [];
        foreach ($rates as $rate) {
            $ids[] = $rate->instrumentId;
            $latest[$rate->instrumentId] = max($latest[$rate->instrumentId] ?? 0, $rate->year);
        }
        $lastClosedYear = (int) $today->format('Y') - 1;
        $states = [];
        foreach ($this->instruments->findMany($workspace, array_values(array_unique($ids))) as $instrument) {
            if (InstrumentType::EURO_FUND !== $instrument->instrumentType) {
                continue;
            }
            $year = $latest[$instrument->id] ?? null;
            $states[] = new EuroFundState(
                InstrumentView::fromInstrument($instrument),
                $year,
                null === $year || $year < $lastClosedYear,
            );
        }
        usort($states, static fn (EuroFundState $left, EuroFundState $right): int => strcasecmp($left->instrument->name, $right->instrument->name));

        return $states;
    }
}
