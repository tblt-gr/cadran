<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain\Recurrence;

use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * The bounded slice of history a scan is allowed to read.
 *
 * `$partial` says the bound was reached and the answer describes less than the
 * whole window; it travels to the caller rather than being scanned away.
 * The workspace is carried and re-checked here so a grouping can never be
 * built from two workspaces' movements even if a query forgot its predicate.
 */
final readonly class RecurrenceObservationWindow
{
    /**
     * @param list<RecurrenceObservation> $observations
     */
    public function __construct(
        public WorkspaceScope $workspace,
        public array $observations,
        public bool $partial,
    ) {
        foreach ($observations as $observation) {
            if (!$observation->workspace->equals($workspace)) {
                throw new \UnexpectedValueException('A recurrence observation escaped its requested workspace.');
            }
        }
    }
}
