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
}
