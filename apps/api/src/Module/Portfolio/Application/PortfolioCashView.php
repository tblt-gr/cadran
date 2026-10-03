<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Portfolio\Domain\Cash\CashLedgerResult;
use App\Module\Portfolio\Domain\Cash\CashWarning;

final readonly class PortfolioCashView
{
    /** @param list<CashWarning> $warnings */
    public function __construct(
        public string $from,
        public string $to,
        public string $assetCode,
        public DecimalValue $currentBalance,
        public CashLedgerResult $ledger,
        public array $warnings,
    ) {
    }
}
