<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Transactions\Domain\BulkCategorizationSkipReason;

final readonly class BulkCategorizeResult
{
    /**
     * @param array<string, BulkCategorizationSkipReason> $skipped    reason per transaction identifier
     * @param list<string>                                $periodDays one booked day per month the write touched
     */
    public function __construct(
        public int $requested,
        public int $changed,
        public array $skipped,
        public array $periodDays,
    ) {
    }
}
