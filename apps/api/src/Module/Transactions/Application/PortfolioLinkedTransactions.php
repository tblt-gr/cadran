<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * Which transactions an active portfolio operation points at. Such a row mirrors a portfolio cash movement and
 * must not drift from it. The Portfolio module implements it, which keeps the dependency pointing from Portfolio
 * to Transactions.
 */
interface PortfolioLinkedTransactions
{
    /**
     * @param list<string> $transactionIds
     *
     * @return list<string> the subset that is linked
     */
    public function among(WorkspaceScope $workspace, array $transactionIds): array;
}
