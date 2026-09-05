<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Accounts\Domain\AccountRuleOverrideRepository;
use App\Module\Accounts\Domain\AccountRuleOverrides;
use App\Module\Foundation\Application\CallerWorkspace;

/**
 * Every override ever recorded on one account, withdrawn ones included and
 * whatever business date is being looked at.
 *
 * It is a different question from {@see ReadAccountRules}, which answers what
 * applies on one day. This answers what was ever claimed, by whom and why —
 * the history a holder needs to explain why their figures diverge from the
 * published ones, and the only place a withdrawn claim remains visible.
 */
final readonly class ListAccountRuleOverrides
{
    public function __construct(
        private CallerWorkspace $caller,
        private AccountRepository $accounts,
        private AccountRuleOverrideRepository $overrides,
    ) {
    }

    public function __invoke(string $accountId): AccountRuleOverrides
    {
        $workspace = $this->caller->resolve();

        $account = $this->accounts->find($workspace, $accountId);
        if (null === $account) {
            throw new AccountNotFound('No account carries this identifier in this workspace.');
        }

        return $this->overrides->findForAccount($workspace, $account->id);
    }
}
