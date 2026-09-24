<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Foundation\Application\CallerWorkspace;

/**
 * One account of the calling workspace, read by identifier.
 *
 * A closed or archived account answers like any other: the detail page it
 * feeds stays linkable once the account leaves the working set, which is what
 * makes a past statement re-readable. An identifier that names no account of
 * this workspace — unknown, or belonging to another one — answers the same
 * absence, so the response never confirms that a stranger's account exists.
 *
 * The account carries no balance of its own. The valuation attached here is
 * the current dated reading resolved through the shared valuation policy, and
 * its absence stays MISSING rather than becoming a zero.
 */
final readonly class ReadAccount
{
    public function __construct(
        private CallerWorkspace $caller,
        private AccountRepository $accounts,
        private ResolveAccountValuation $valuations,
    ) {
    }

    public function __invoke(string $id): AccountView
    {
        $workspace = $this->caller->resolve();
        $account = $this->accounts->find($workspace, $id);
        if (null === $account) {
            throw new AccountNotFound('No account carries this identifier in this workspace.');
        }

        return AccountView::fromAccount(
            $account,
            valuation: $this->valuations->current($workspace, $account),
        );
    }
}
