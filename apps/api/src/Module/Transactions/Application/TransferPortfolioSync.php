<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\Transaction;

/**
 * Keeps the portfolio side of a transfer in step with its two legs. It is called inside the transfer's own
 * transaction after every create, edit and void, and is idempotent: it compares what the legs say with what the
 * portfolio holds and only writes the difference, so one entry point serves the three use cases.
 *
 * The Portfolio module implements it; Transactions only knows this port, which keeps the dependency pointing from
 * Portfolio to Transactions.
 */
interface TransferPortfolioSync
{
    /**
     * @param Transaction $source the outgoing leg as it stands after the change
     * @param Transaction $target the incoming leg as it stands after the change
     *
     * @throws TransferPortfolioSyncRefused when the portfolio refuses the matching operation, which rolls the transfer back
     */
    public function __invoke(WorkspaceScope $workspace, Transaction $source, Transaction $target): void;
}
