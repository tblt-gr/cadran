<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain;

use App\Module\Foundation\Domain\AssetCode;

final readonly class MonthlyBudgetActual
{
    /** @param list<MonthlyBudgetActualSource> $sources */
    public function __construct(
        public ?string $amount,
        public ?AssetCode $asset,
        public ?MonthlyProjectionReason $reason,
        public array $sources,
        public int $pendingCount,
        /** Negated like the amount; null when no planned movement falls in the scope. */
        public ?string $upcomingAmount = null,
        public int $upcomingCount = 0,
    ) {
        if ((null === $amount) === (null === $reason) || (null === $amount) !== (null === $asset)) {
            throw new \InvalidArgumentException('A monthly budget actual carries either an amount and asset or a reason.');
        }
    }
}
