<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Accounts\Domain\AccountValuationMode;
use App\Module\Catalog\Domain\AccountKind;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Domain\InvalidPortfolioOperation;

/** The one place that decides whether an account can carry portfolio operations. */
final readonly class PortfolioAccountGate
{
    public function __construct(private AccountRepository $accounts)
    {
    }

    /** A portfolio account of the workspace, whatever its lifecycle: history stays readable. */
    public function forRead(WorkspaceScope $workspace, string $accountId): Account
    {
        $account = $this->accounts->find($workspace, $accountId) ?? throw new PortfolioAccountNotFound();

        return $this->assertPortfolio($account);
    }

    /** An active portfolio account; with $lock the row is held until the transaction ends, serialising every writer. */
    public function forWrite(WorkspaceScope $workspace, string $accountId, bool $lock): Account
    {
        $account = ($lock ? $this->accounts->findForUpdate($workspace, $accountId) : $this->accounts->find($workspace, $accountId))
            ?? throw new PortfolioAccountNotFound();
        $this->assertPortfolio($account);
        if (null !== $account->archivedAt || $account->isClosed()) {
            throw new InvalidPortfolioOperation('The portfolio account is closed or archived.', 'portfolio_account_inactive');
        }

        return $account;
    }

    private function assertPortfolio(Account $account): Account
    {
        if (AccountKind::PORTFOLIO !== $account->kind || AccountValuationMode::PORTFOLIO !== $account->valuationMode) {
            throw new InvalidPortfolioOperation('The account is not followed in portfolio mode.', 'account_not_portfolio');
        }

        return $account;
    }
}
