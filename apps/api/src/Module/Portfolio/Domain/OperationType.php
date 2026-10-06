<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain;

enum OperationType: string
{
    case PREMIUM = 'PREMIUM';
    case PARTIAL_SURRENDER = 'PARTIAL_SURRENDER';
    case FULL_SURRENDER = 'FULL_SURRENDER';

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

    public function isSurrender(): bool
    {
        return self::PARTIAL_SURRENDER === $this || self::FULL_SURRENDER === $this;
    }

    public function hasSupportLegs(): bool
    {
        return self::PREMIUM === $this || $this->isSurrender();
    }

    /** The only types that move money across the portfolio boundary. */
    public function isExternalCashFlow(): bool
    {
        return self::DEPOSIT === $this || self::WITHDRAWAL === $this || $this->hasSupportLegs();
    }
}
