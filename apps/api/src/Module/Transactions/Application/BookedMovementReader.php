<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Foundation\Domain\WorkspaceScope;

interface BookedMovementReader
{
    /**
     * The BOOKED transactions of the workspace whose booking day is in the inclusive range, oldest first and by
     * identifier within a day, so the same rows come back in the same order. At most `$limit` rows: a caller that
     * asks for its bound plus one can tell the range is too large.
     *
     * @return list<BookedMovementFact>
     */
    public function between(WorkspaceScope $workspace, \DateTimeImmutable $from, \DateTimeImmutable $to, int $limit): array;
}
