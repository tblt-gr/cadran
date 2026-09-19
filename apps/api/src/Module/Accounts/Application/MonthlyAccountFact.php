<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

final readonly class MonthlyAccountFact
{
    public function __construct(
        public string $id,
        public string $label,
        public string $assetCode,
        public string $kind,
        public bool $savingsDestination,
        public MonthlyAccountValuationFact $beginning,
        public MonthlyAccountValuationFact $end,
        public string $reconciliationStatus,
    ) {
    }
}
