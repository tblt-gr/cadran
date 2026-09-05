<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application\Double;

use App\Module\Accounts\Domain\AccountRuleOverride;
use App\Module\Accounts\Domain\AccountRuleOverrideRepository;
use App\Module\Accounts\Domain\AccountRuleOverrides;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * The overrides of the workspace, held in memory. Like the real repository it
 * answers only for the workspace it was asked about, so a use case that forgot
 * to scope a read fails here rather than in production.
 */
final class InMemoryAccountRuleOverrideRepository implements AccountRuleOverrideRepository
{
    /** @var list<AccountRuleOverride> */
    private array $overrides;

    public function __construct(AccountRuleOverride ...$overrides)
    {
        $this->overrides = array_values($overrides);
    }

    public function findForAccount(WorkspaceScope $workspace, string $accountId): AccountRuleOverrides
    {
        return new AccountRuleOverrides(array_values(array_filter(
            $this->overrides,
            static fn (AccountRuleOverride $override): bool => $override->workspace->id === $workspace->id
                && $override->accountId === $accountId,
        )));
    }

    public function add(AccountRuleOverride $override): void
    {
        $this->overrides[] = $override;
    }

    public function withdraw(AccountRuleOverride $override): bool
    {
        foreach ($this->overrides as $position => $existing) {
            if ($existing->id !== $override->id || !$existing->isStanding()) {
                continue;
            }

            $this->overrides[$position] = $override;

            return true;
        }

        return false;
    }
}
