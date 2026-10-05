<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\LifeInsurance;

final class LifeInsuranceAuditEvents
{
    public const string CONTRACT = 'life_insurance_contract';
    public const string CONTRACT_CREATED = 'life_insurance_contract.created';
    public const string CONTRACT_UPDATED = 'life_insurance_contract.updated';
    public const string FEE_TERMS = 'life_insurance_fee_terms';
    public const string FEE_TERMS_ADDED = 'life_insurance_fee_terms.added';
    public const string FEE_TERMS_REMOVED = 'life_insurance_fee_terms.removed';
    public const string RATE = 'euro_fund_rate';
    public const string RATE_RECORDED = 'euro_fund_rate.recorded';
    public const string RATE_REMOVED = 'euro_fund_rate.removed';
}
