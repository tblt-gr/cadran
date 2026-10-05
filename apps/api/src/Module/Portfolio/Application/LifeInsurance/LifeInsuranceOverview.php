<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\LifeInsurance;

use App\Module\Portfolio\Domain\LifeInsurance\EuroFundRate;
use App\Module\Portfolio\Domain\LifeInsurance\FeeTerms;
use App\Module\Portfolio\Domain\LifeInsurance\LifeInsuranceContract;

final readonly class LifeInsuranceOverview
{
    /**
     * @param list<FeeTerms>      $feeTerms
     * @param list<EuroFundRate>  $euroFundRates
     * @param list<EuroFundState> $euroFunds
     */
    public function __construct(
        public string $assetCode,
        public ?LifeInsuranceContract $contract,
        public array $feeTerms,
        public array $euroFundRates,
        public array $euroFunds,
    ) {
    }
}
