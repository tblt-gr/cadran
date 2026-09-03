<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

/**
 * How quickly the value of an account can be turned into spendable money.
 *
 * It is the account holder's declaration, not a market measurement: reports
 * group by it, and an emergency-fund policy reads it rather than guessing from
 * the product family.
 */
enum LiquidityLevel: string
{
    case IMMEDIATE = 'IMMEDIATE';
    case SHORT_TERM = 'SHORT_TERM';
    case MEDIUM_TERM = 'MEDIUM_TERM';
    case LONG_TERM = 'LONG_TERM';
    case ILLIQUID = 'ILLIQUID';
}
