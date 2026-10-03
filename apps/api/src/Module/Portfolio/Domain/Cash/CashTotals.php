<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Cash;

use App\Module\Foundation\Domain\DecimalValue;

/** Gross components of a period: fees and taxes are separate costs, each stored value counted once whatever the type. */
final readonly class CashTotals
{
    public function __construct(
        public DecimalValue $deposits,
        public DecimalValue $withdrawals,
        public DecimalValue $buysGross,
        public DecimalValue $sellsGross,
        public DecimalValue $dividendsGross,
        public DecimalValue $interestGross,
        public DecimalValue $fees,
        public DecimalValue $taxes,
        public DecimalValue $cashInLieu,
        public DecimalValue $roundingResiduals,
    ) {
    }
}
