<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Accounts\Application\CreateAccount;
use App\Module\Accounts\Application\CreateAccountInput;
use App\Module\Accounts\Application\ListAccountGroups;
use App\Module\Foundation\Application\DemoDataContributor;
use App\Module\Portfolio\Application\LifeInsurance\AddFeeTerms;
use App\Module\Portfolio\Application\LifeInsurance\EuroFundRateInput;
use App\Module\Portfolio\Application\LifeInsurance\FeeTermsInput;
use App\Module\Portfolio\Application\LifeInsurance\LifeInsuranceContractInput;
use App\Module\Portfolio\Application\LifeInsurance\RecordEuroFundRate;
use App\Module\Portfolio\Application\LifeInsurance\SaveLifeInsuranceContract;
use Symfony\Component\Clock\ClockInterface;

/** An empty multi-support wrapper; premiums and operations belong to the next ticket. */
final readonly class SeedDemoLifeInsurance implements DemoDataContributor
{
    private const string OPENING_DAY = '2023-12-31';

    public function __construct(
        private CreateAccount $createAccount,
        private ListAccountGroups $listGroups,
        private CreateInstrument $createInstrument,
        private SaveLifeInsuranceContract $saveContract,
        private AddFeeTerms $addFeeTerms,
        private RecordEuroFundRate $recordRate,
        private ClockInterface $clock,
        private RecordPortfolioValuation $recordValuation,
    ) {
    }

    public function seed(): void
    {
        $groupId = null;
        foreach ($this->listGroups->__invoke(false, 1, ListAccountGroups::MAX_PAGE_SIZE)->items as $group) {
            if ('Investissements' === $group->label) {
                $groupId = $group->id;
                break;
            }
        }
        $account = ($this->createAccount)(new CreateAccountInput(
            label: 'Assurance-vie', assetCode: 'EUR', kind: 'INSURANCE_CONTRACT', productCode: 'FR_LIFE_INSURANCE',
            productModelId: null, institution: 'Assureur Démo', maskedIdentifier: null, valuationMode: 'PORTFOLIO',
            liquidityLevel: 'MEDIUM_TERM', includeInNetWorth: true, includeInEmergencyFund: false,
            openedOn: self::OPENING_DAY, closedOn: null, primaryGroupId: $groupId,
        ));
        ($this->saveContract)($account->id, new LifeInsuranceContractInput(
            insurer: 'Assureur Démo', distributor: null, commercialName: 'Contrat Démo Vie',
            contractNumberProvided: true, contractNumber: 'AV-00012345', subscribedOn: self::OPENING_DAY,
            taxStartOn: null, managementMode: 'FREE', version: null,
        ));
        $fund = ($this->createInstrument)(new CreateInstrumentInput(
            name: 'Fonds euros Démo', isin: null, ticker: null, venueMic: null, instrumentType: 'EURO_FUND',
            assetClass: null, region: null, currencyCode: 'EUR',
        ));
        ($this->createInstrument)(new CreateInstrumentInput(
            name: 'Fonds Actions Démo', isin: 'FRDEMO000064', ticker: null, venueMic: null, instrumentType: 'FUND',
            assetClass: 'EQUITY', region: null, currencyCode: 'EUR',
        ));
        ($this->addFeeTerms)($account->id, new FeeTermsInput(
            self::OPENING_DAY, '1', '0.60', '0.85', '0.50', null, 'Barème de démonstration',
        ));
        // Empty holdings and derived cash sum exactly to zero, allowing historical month closing.
        $today = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0);
        for ($month = new \DateTimeImmutable('2024-01-01'); $month->modify('last day of this month') < $today; $month = $month->modify('+1 month')) {
            ($this->recordValuation)($account->id, new ValuationInput(
                $month->modify('last day of this month')->format('Y-m-d'), 'POSITIONS', null, [], null, null, null, null,
            ));
        }
        $year = (int) $today->format('Y');
        foreach ([[$year - 2, '2.50'], [$year - 1, '2.80']] as [$rateYear, $rate]) {
            ($this->recordRate)($account->id, new EuroFundRateInput(
                $fund->id, $rateYear, $rate, null, 'NET_OF_MANAGEMENT_FEES', 'Taux de démonstration',
            ));
        }
    }
}
