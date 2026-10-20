<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

final readonly class ValuationDerivationView
{
    public function __construct(
        public string $anchorAsOf,
        public int $anchorAgeDays,
        public int $movementCount,
        public int $pendingCount,
    ) {
    }
}
