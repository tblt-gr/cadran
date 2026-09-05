<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountBalanceSnapshotRepository;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Foundation\Application\CallerWorkspace;

/**
 * The snapshot history of one account, superseded rows included. The trail
 * of what was recorded is what a later statement must still be able to
 * explain; the latest valid figure on a day is the valuation resource.
 */
final readonly class ListAccountBalances
{
    public const int DEFAULT_PAGE_SIZE = 50;
    public const int MAX_PAGE_SIZE = 100;
    public const int MAX_PAGE = 1000;

    public function __construct(
        private CallerWorkspace $caller,
        private AccountRepository $accounts,
        private AccountBalanceSnapshotRepository $snapshots,
    ) {
    }

    public function __invoke(string $accountId, ?int $page, ?int $perPage): AccountBalanceSnapshotPage
    {
        $requestedPage = $page ?? 1;
        $pageSize = $perPage ?? self::DEFAULT_PAGE_SIZE;
        if ($requestedPage < 1 || $requestedPage > self::MAX_PAGE || $pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE) {
            throw new InvalidAccountBalanceInput('The snapshot page is outside its bounds.');
        }

        $workspace = $this->caller->resolve();
        $account = $this->accounts->find($workspace, $accountId);
        if (null === $account) {
            throw new AccountNotFound('No account carries this identifier in this workspace.');
        }

        return new AccountBalanceSnapshotPage(
            items: $this->snapshots->pageForAccount(
                $workspace,
                $account->id,
                $pageSize,
                ($requestedPage - 1) * $pageSize,
            ),
            page: $requestedPage,
            perPage: $pageSize,
            total: $this->snapshots->countForAccount($workspace, $account->id),
        );
    }
}
