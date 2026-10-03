<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\TransactionNature;

interface AccountTransactionFactReader
{
    public const int LIMIT = 2000;

    /**
     * Oldest first, voided rows included and flagged. Returns at most LIMIT + 1 rows, so a caller can tell the range is too large.
     *
     * @param list<TransactionNature> $natures
     *
     * @return list<AccountTransactionFact>
     */
    public function between(WorkspaceScope $workspace, string $accountId, \DateTimeImmutable $from, \DateTimeImmutable $to, array $natures): array;
}
