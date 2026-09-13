<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain\Recurrence;

use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * The read side detection scans. It only ever reads, and only ever inside one
 * workspace: a candidate that grouped two workspaces' movements would let one
 * infer the other's history.
 */
interface RecurrenceObservationRepository
{
    /**
     * The bounded window of scannable movements: booked on or after `$from`,
     * non-voided, of a nature a recurrence can be recognised from, and never
     * more than `$limit` rows. Reaching the limit returns the most recent rows
     * and marks the window partial rather than scanning further.
     */
    public function window(WorkspaceScope $workspace, \DateTimeImmutable $from, int $limit): RecurrenceObservationWindow;
}
