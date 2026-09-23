<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

final readonly class MonthlyLedgerMovementPageView
{
    /** @param list<MonthlyLedgerMovementView> $items */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
        public bool $hasMore,
        public int $pageSize,
    ) {
    }
}
