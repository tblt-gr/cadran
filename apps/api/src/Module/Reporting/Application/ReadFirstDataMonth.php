<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Accounts\Application\ReadFirstValuationMonth;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Application\ReadFirstTransactionMonth;

/**
 * The oldest calendar month holding either a transaction or an account valuation: a workspace
 * with balance snapshots only still has net worth to report from that month on.
 */
final readonly class ReadFirstDataMonth
{
    public function __construct(
        private ReadFirstTransactionMonth $firstTransaction,
        private ReadFirstValuationMonth $firstValuation,
    ) {
    }

    /** @return string|null `YYYY-MM`, or null when the workspace holds no data at all */
    public function __invoke(WorkspaceScope $workspace): ?string
    {
        $keys = array_filter([($this->firstTransaction)($workspace), ($this->firstValuation)($workspace)]);

        return [] === $keys ? null : min($keys);
    }
}
