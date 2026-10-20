<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

final readonly class AccountValuationHistoryView
{
    /** @param list<AccountValuationHistoryPoint> $points */
    public function __construct(
        public string $accountId,
        public string $from,
        public string $to,
        public bool $hasHistory,
        public array $points,
    ) {
    }
}
