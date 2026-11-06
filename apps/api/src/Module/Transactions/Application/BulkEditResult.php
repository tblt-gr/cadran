<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Transactions\Domain\BulkEditSkipReason;

final readonly class BulkEditResult
{
    /**
     * @param array<string, BulkEditSkipReason> $skipped       reason per transaction identifier
     * @param list<string>                      $fieldsChanged fields written on at least one row
     * @param list<string>                      $periodDays    one booked day per month the write touched
     */
    public function __construct(
        public int $requested,
        public int $changed,
        public array $skipped,
        public array $fieldsChanged,
        public array $periodDays,
    ) {
    }
}
