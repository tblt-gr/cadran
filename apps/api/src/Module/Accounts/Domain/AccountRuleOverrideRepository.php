<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Foundation\Domain\WorkspaceScope;

interface AccountRuleOverrideRepository
{
    /**
     * Every override ever recorded on one account of this workspace,
     * withdrawn ones included. An account with none answers with an empty set
     * rather than with null: no override is a complete answer, not a missing
     * one.
     */
    public function findForAccount(WorkspaceScope $workspace, string $accountId): AccountRuleOverrides;

    public function add(AccountRuleOverride $override): void;

    /**
     * Records the withdrawal of an override that was standing. Returns false
     * when the row is no longer standing, which is how a second concurrent
     * withdrawal of the same override is told apart from the first.
     */
    public function withdraw(AccountRuleOverride $override): bool;
}
