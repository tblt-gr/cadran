<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountBalanceSnapshotRepository;
use App\Module\Foundation\Domain\WorkspaceScope;

/** The calendar month of the workspace's oldest active balance snapshot, so a report never counts months before any data. */
final readonly class ReadFirstValuationMonth
{
    public function __construct(private AccountBalanceSnapshotRepository $snapshots)
    {
    }

    /** @return string|null `YYYY-MM`, or null when the workspace has no active snapshot */
    public function __invoke(WorkspaceScope $workspace): ?string
    {
        return $this->snapshots->firstActiveValuedOn($workspace)?->format('Y-m');
    }
}
