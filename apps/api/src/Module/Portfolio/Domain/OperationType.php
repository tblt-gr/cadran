<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain;

enum OperationType: string
{
    case EURO_FUND_INTEREST = 'EURO_FUND_INTEREST';
    case PROFIT_SHARING = 'PROFIT_SHARING';
    case MANAGEMENT_FEE = 'MANAGEMENT_FEE';
    case SOCIAL_CONTRIBUTION = 'SOCIAL_CONTRIBUTION';

    case PREMIUM = 'PREMIUM';
    case PARTIAL_SURRENDER = 'PARTIAL_SURRENDER';
    case FULL_SURRENDER = 'FULL_SURRENDER';
    case SWITCH = 'SWITCH';

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

    public function isCreditedInterest(): bool
    {
        return self::EURO_FUND_INTEREST === $this || self::PROFIT_SHARING === $this;
    }

    public function isObservedInsurance(): bool
    {
        return $this->isCreditedInterest() || self::MANAGEMENT_FEE === $this || self::SOCIAL_CONTRIBUTION === $this;
    }

    public function isSurrender(): bool
    {
        return self::PARTIAL_SURRENDER === $this || self::FULL_SURRENDER === $this;
    }

    public function hasSupportLegs(): bool
    {
        return self::PREMIUM === $this || self::SWITCH === $this || $this->isSurrender();
    }

    /** The only types that move money across the portfolio boundary. */
    public function isExternalCashFlow(): bool
    {
        return self::DEPOSIT === $this || self::WITHDRAWAL === $this || self::PREMIUM === $this || $this->isSurrender();
    }
}
