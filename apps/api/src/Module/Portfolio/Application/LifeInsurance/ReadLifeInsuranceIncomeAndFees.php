<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\LifeInsurance;

use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Portfolio\Application\PortfolioAccountGate;
use App\Module\Portfolio\Domain\InvalidPortfolioOperation;
use App\Module\Portfolio\Domain\LifeInsurance\EuroFundRateRepository;
use App\Module\Portfolio\Domain\LifeInsurance\IncomeAndFeesCalculator;
use App\Module\Portfolio\Domain\LifeInsurance\LifeInsuranceContractRepository;
use App\Module\Portfolio\Domain\OperationFilters;
use App\Module\Portfolio\Domain\PortfolioOperationRepository;

/** @phpstan-import-type AnnualIncome from IncomeAndFeesCalculator */
final readonly class ReadLifeInsuranceIncomeAndFees
{
    private const int MAX_OPERATIONS = 2000;

    public function __construct(private CallerWorkspaceContext $caller, private PortfolioAccountGate $gate,
        private LifeInsuranceContractRepository $contracts, private PortfolioOperationRepository $operations,
        private EuroFundRateRepository $rates, private WorkspaceCalendar $calendar)
    {
    }

    /** @return AnnualIncome */
    public function __invoke(string $accountId, string $yearLiteral): array
    {
        $workspace = $this->caller->resolveContext()->workspace;
        $account = $this->gate->forLifeInsurance($workspace, $accountId, false);
        $contract = $this->contracts->findByAccount($workspace, $account->id)
            ?? throw new InvalidPortfolioOperation('Record the contract before reading its income.', 'life_insurance_contract_required');
        $year = (int) $yearLiteral;
        if (1 !== preg_match('/^[0-9]{4}$/D', $yearLiteral) || $year < (int) $contract->subscribedOn->format('Y') || $year > (int) $this->calendar->today()->format('Y')) {
            throw new InvalidPortfolioOperation('The year lies outside the contract period.', 'invalid_year');
        }
        $filters = new OperationFilters(new \DateTimeImmutable($yearLiteral.'-01-01'), new \DateTimeImmutable($yearLiteral.'-12-31'), [], null, false);
        if ($this->operations->count($workspace, $accountId, $filters) > self::MAX_OPERATIONS) {
            throw new InvalidPortfolioOperation('The year holds too many operations.', 'cash_range_too_dense');
        }
        $operations = $this->operations->search($workspace, $accountId, $filters, self::MAX_OPERATIONS + 1, 0);
        if (count($operations) > self::MAX_OPERATIONS) {
            throw new InvalidPortfolioOperation('The year holds too many operations.', 'cash_range_too_dense');
        }

        return $this->summarize($year, $account->assetCode->toString(), $operations, $this->rates->listForContract($workspace, $contract->id));
    }

    /** @param list<\App\Module\Portfolio\Domain\PortfolioOperation> $operations
     * @param list<\App\Module\Portfolio\Domain\LifeInsurance\EuroFundRate> $rates
     *
     * @return AnnualIncome
     */
    public function summarize(int $year, string $assetCode, array $operations, array $rates): array
    {
        if (count($operations) > self::MAX_OPERATIONS) {
            throw new InvalidPortfolioOperation('The year holds too many operations.', 'cash_range_too_dense');
        }
        try {
            return IncomeAndFeesCalculator::calculate($year, $assetCode, $operations, $rates);
        } catch (\App\Module\Foundation\Domain\PrecisionExceeded|\App\Module\Foundation\Domain\MalformedDecimal) {
            throw new InvalidPortfolioOperation('Annual sums exceed the supported precision.', 'cash_amount_too_large');
        }
    }
}
