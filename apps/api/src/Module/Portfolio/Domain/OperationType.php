<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain;

enum OperationType: string
{
    case DEPOSIT = 'DEPOSIT';
    case WITHDRAWAL = 'WITHDRAWAL';
    case BUY = 'BUY';
    case SELL = 'SELL';
    case DIVIDEND = 'DIVIDEND';
    case INTEREST = 'INTEREST';
    case FEE = 'FEE';
    case TAX = 'TAX';
    case SECURITY_TRANSFER_IN = 'SECURITY_TRANSFER_IN';
    case SECURITY_TRANSFER_OUT = 'SECURITY_TRANSFER_OUT';
    case SPLIT = 'SPLIT';
    case MERGER = 'MERGER';
    case SPIN_OFF = 'SPIN_OFF';

    /** The only types that move money across the portfolio boundary. */
    public function isExternalCashFlow(): bool
    {
        return self::DEPOSIT === $this || self::WITHDRAWAL === $this;
    }
}
