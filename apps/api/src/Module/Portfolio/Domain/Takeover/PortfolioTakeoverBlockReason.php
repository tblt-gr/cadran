<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Takeover;

/** Why an account cannot take over its positions, in the order the rules are checked. */
enum PortfolioTakeoverBlockReason: string
{
    case NOT_SUPPORTED = 'NOT_SUPPORTED';
    case ALREADY_TAKEN_OVER = 'ALREADY_TAKEN_OVER';
    case ACTIVE_VALUATION = 'ACTIVE_VALUATION';
    case SECURITY_OPERATION = 'SECURITY_OPERATION';
    case CASH_OPERATION_BEFORE = 'CASH_OPERATION_BEFORE';
}
