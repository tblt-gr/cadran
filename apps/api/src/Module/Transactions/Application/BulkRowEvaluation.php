<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Transactions\Domain\BulkCategorizationFacts;
use App\Module\Transactions\Domain\BulkEditSkipReason;
use App\Module\Transactions\Domain\Transaction;

/** What a bulk run may do to its rows, decided once and shared by the preview and the apply. */
final readonly class BulkRowEvaluation
{
    /**
     * @param array<string, BulkEditSkipReason>      $skipped  rows refused whole, by identifier
     * @param array<string, Transaction>             $eligible rows the run may write, by identifier in lock order
     * @param array<string, BulkCategorizationFacts> $facts
     * @param list<BulkMoveImpact>                   $impacts  empty unless the run moves rows
     */
    public function __construct(
        public array $skipped,
        public array $eligible,
        public array $facts,
        public array $impacts,
    ) {
    }

    /** @return array<string, int> version per eligible identifier, what a preview token binds to */
    public function eligibleVersions(): array
    {
        return array_map(static fn (Transaction $row): int => $row->version, $this->eligible);
    }
}
