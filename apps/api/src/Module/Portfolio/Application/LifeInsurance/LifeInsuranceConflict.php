<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\LifeInsurance;

final class LifeInsuranceConflict extends \RuntimeException
{
    public const string CONTRACT_EXISTS = 'life_insurance_contract_exists';
    public const string STALE_VERSION = 'stale_contract_version';
    public const string FEE_TERMS_EXIST = 'fee_terms_exist';
    public const string FEE_TERMS_LIMIT = 'fee_terms_limit_reached';
    public const string RATE_EXISTS = 'euro_fund_rate_exists';
    public const string RATE_LIMIT = 'euro_fund_rate_limit_reached';

    public function __construct(public readonly string $problemCode)
    {
        parent::__construct(sprintf('Life-insurance conflict: %s.', $problemCode));
    }
}
