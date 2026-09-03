<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Foundation\Application\CallerWorkspace;

final readonly class ListAccounts
{
    public const int DEFAULT_PAGE_SIZE = 50;
    public const int MAX_PAGE_SIZE = 100;
    public const int MAX_PAGE = 1000;

    public function __construct(
        private CallerWorkspace $caller,
        private AccountRepository $accounts,
    ) {
    }

    public function __invoke(
        bool $includeArchived,
        bool $includeClosed,
        ?int $page,
        ?int $perPage,
        ?string $kind = null,
    ): AccountPage {
        $requestedPage = $page ?? 1;
        $pageSize = $perPage ?? self::DEFAULT_PAGE_SIZE;
        if ($requestedPage < 1 || $requestedPage > self::MAX_PAGE || $pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE) {
            throw new InvalidAccountInput('The account page is outside its bounds.');
        }

        $accountKind = null === $kind ? null : AccountInputParser::kind($kind);

        $workspace = $this->caller->resolve();
        $accounts = $this->accounts->list(
            $workspace,
            $includeArchived,
            $includeClosed,
            $pageSize,
            ($requestedPage - 1) * $pageSize,
            $accountKind,
        );

        return new AccountPage(
            items: array_map(AccountView::fromAccount(...), $accounts),
            page: $requestedPage,
            perPage: $pageSize,
            total: $this->accounts->count($workspace, $includeArchived, $includeClosed, $accountKind),
        );
    }
}
