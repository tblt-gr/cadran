<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Reconciliation;

use App\Module\Accounts\Domain\AccountBalanceSnapshotRepository;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Foundation\Application\CallerWorkspaceContext;

final readonly class ReadAccountReconciliation
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private AccountRepository $accounts,
        private AccountBalanceSnapshotRepository $snapshots,
        private AccountReconciliationCalculator $calculator,
    ) {
    }

    public function __invoke(string $accountId, string $snapshotId, string $periodStart): AccountReconciliationView
    {
        $workspace = $this->caller->resolveContext()->workspace;
        $account = $this->accounts->find($workspace, $accountId);
        $closing = null === $account ? null : $this->snapshots->find($workspace, $account->id, $snapshotId);
        if (null === $closing) {
            throw new AccountReconciliationNotFound('No such snapshot on this account in this workspace.');
        }

        $start = $this->calculator->periodStart($periodStart, $closing);

        return $this->calculator->view($workspace, $closing, $this->calculator->compare($workspace, $closing, $start));
    }
}
