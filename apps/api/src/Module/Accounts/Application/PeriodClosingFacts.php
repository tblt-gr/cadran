<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountBalanceSnapshot;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * What closing needs to know about movements. The movements live in another
 * module, which implements this port: Accounts never imports it.
 */
interface PeriodClosingFacts
{
    /** PENDING transactions of the workspace booked between two days, inclusive. */
    public function pendingTransactionCount(WorkspaceScope $workspace, \DateTimeImmutable $from, \DateTimeImmutable $to): int;

    /**
     * The non-zero gaps `observed - computed` of these unreconciled closing
     * snapshots against the movements since $from, keyed by account identifier.
     * A figure that cannot be calculated is absent: it is not a gap.
     *
     * @param list<AccountBalanceSnapshot> $closings
     *
     * @return array<string, DecimalValue>
     */
    public function unexplainedDiscrepancies(WorkspaceScope $workspace, array $closings, \DateTimeImmutable $from): array;
}
