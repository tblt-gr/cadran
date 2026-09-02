<?php

declare(strict_types=1);

namespace App\Module\Catalog\Domain;

/**
 * The legal or fiscal envelope a product sits in. It decides which rules may
 * apply to it — a regulated ceiling, a tax reference — independently of what
 * the account holds.
 */
enum WrapperKind: string
{
    case NONE = 'NONE';
    case REGULATED_SAVINGS = 'REGULATED_SAVINGS';
    case TAX_WRAPPER = 'TAX_WRAPPER';
    case SECURITIES_ACCOUNT = 'SECURITIES_ACCOUNT';
    case LIFE_INSURANCE = 'LIFE_INSURANCE';
    case RETIREMENT = 'RETIREMENT';
    case EMPLOYEE_SAVINGS = 'EMPLOYEE_SAVINGS';

    /**
     * The rule kinds an envelope is expected to carry. When no sourced period
     * covers the requested business date, these kinds remain visible as
     * unavailable instead of disappearing from the response.
     *
     * @return list<RuleKind>
     */
    public function expectedRuleKinds(): array
    {
        return match ($this) {
            self::REGULATED_SAVINGS => [RuleKind::DEPOSIT_CEILING],
            self::TAX_WRAPPER => [
                RuleKind::CONTRIBUTION_CEILING,
                RuleKind::COMBINED_CONTRIBUTION_CEILING,
            ],
            default => [],
        };
    }
}
