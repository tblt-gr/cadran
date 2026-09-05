<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Catalog\Domain\BusinessDay;
use App\Module\Foundation\Application\CallerWorkspace;

/**
 * The latest valid snapshot of one account on a requested date, with age
 * and missing/stale quality. Absence stays null.
 */
final readonly class ReadAccountValuation
{
    public function __construct(
        private CallerWorkspace $caller,
        private AccountRepository $accounts,
        private ResolveAccountValuation $valuations,
    ) {
    }

    public function __invoke(string $accountId, ?string $asOf): ValuationView
    {
        $workspace = $this->caller->resolve();
        $account = $this->accounts->find($workspace, $accountId);
        if (null === $account) {
            throw new AccountNotFound('No account carries this identifier in this workspace.');
        }

        if (null === $asOf || '' === $asOf) {
            return $this->valuations->current($workspace, $account);
        }

        try {
            $requestedOn = BusinessDay::fromIsoDate($asOf)->date;
        } catch (\Throwable $exception) {
            throw new InvalidAccountBalanceInput('The valuation date must be an ISO 8601 calendar day.', previous: $exception);
        }

        return $this->valuations->on($workspace, $account, $requestedOn);
    }
}
