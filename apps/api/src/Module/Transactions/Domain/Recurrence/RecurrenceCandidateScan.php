<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain\Recurrence;

/**
 * The result of one detection run. `$partial` repeats the window's bound: the
 * scan stopped at the cap and the proposals describe only the movements it
 * could read, which the caller must say rather than scan further.
 */
final readonly class RecurrenceCandidateScan
{
    /**
     * @param list<RecurrenceCandidate> $candidates
     */
    public function __construct(
        public array $candidates,
        public bool $partial,
    ) {
    }
}
