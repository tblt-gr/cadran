<?php

declare(strict_types=1);

namespace App\Module\Catalog\Domain;

/**
 * What a product holds. Deliberately closed: an account whose kind is unknown
 * cannot be given the right balance, valuation or net-worth treatment later.
 */
enum AccountKind: string
{
    case CURRENT = 'CURRENT';
    case SAVINGS = 'SAVINGS';
    case PORTFOLIO = 'PORTFOLIO';
    case INSURANCE_CONTRACT = 'INSURANCE_CONTRACT';
    case EMPLOYEE_BENEFIT = 'EMPLOYEE_BENEFIT';
    case CASH = 'CASH';
    case REAL_ASSET = 'REAL_ASSET';
    case LIABILITY = 'LIABILITY';

    /**
     * Which side of the balance sheet the kind sits on. It is derived here
     * rather than recorded beside the kind, so no product can ever declare a
     * loan as an asset: one figure decides both, and the two cannot drift.
     */
    public function nature(): ProductNature
    {
        return match ($this) {
            self::LIABILITY => ProductNature::LIABILITY,
            default => ProductNature::ASSET,
        };
    }

    /**
     * Whether this kind sits on the savings side of a transfer: money moved
     * into it builds savings, money moved out of it withdraws from savings.
     * Reporting's monthly net-savings KPI (RPT-007) reads this instead of
     * hard-coding the two kinds itself, so the perimeter has one owner.
     */
    public function isSavingsDestination(): bool
    {
        return match ($this) {
            self::SAVINGS, self::PORTFOLIO => true,
            default => false,
        };
    }
}
