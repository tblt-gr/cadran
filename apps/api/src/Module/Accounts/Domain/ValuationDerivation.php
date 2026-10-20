<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

final readonly class ValuationDerivation
{
    public function __construct(
        public \DateTimeImmutable $anchorAsOf,
        public int $anchorAgeDays,
        public int $movementCount,
        public int $pendingCount,
    ) {
    }
}
