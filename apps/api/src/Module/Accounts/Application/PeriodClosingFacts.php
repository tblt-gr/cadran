<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountBalanceSnapshot;
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
     * How many of these unreconciled closing snapshots show a non-zero
     * discrepancy against the movements since $from.
     *
     * @param list<AccountBalanceSnapshot> $closings
     */
    public function unexplainedDiscrepancyCount(WorkspaceScope $workspace, array $closings, \DateTimeImmutable $from): int;
}
