<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountBalanceSnapshot;
use App\Module\Accounts\Domain\AccountBalanceSnapshotRepository;
use App\Module\Accounts\Domain\AccountBalanceSnapshots;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Accounts\Domain\AccountValuation;
use App\Module\Catalog\Domain\BusinessDay;
use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Reference\Application\AssetCatalog;
use Symfony\Component\Clock\ClockInterface;

final readonly class ListAccounts
{
    public const int DEFAULT_PAGE_SIZE = 50;
    public const int MAX_PAGE_SIZE = 100;
    public const int MAX_PAGE = 1000;

    public function __construct(
        private CallerWorkspace $caller,
        private AccountRepository $accounts,
        private AccountBalanceSnapshotRepository $snapshots,
        private AssetCatalog $assets,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(
        bool $includeArchived,
        bool $includeClosed,
        ?int $page,
        ?int $perPage,
        ?string $kind = null,
        ?string $asOf = null,
    ): AccountPage {
        $requestedPage = $page ?? 1;
        $pageSize = $perPage ?? self::DEFAULT_PAGE_SIZE;
        if ($requestedPage < 1 || $requestedPage > self::MAX_PAGE || $pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE) {
            throw new InvalidAccountInput('The account page is outside its bounds.');
        }

        $accountKind = null === $kind ? null : AccountInputParser::kind($kind);
        $requestedOn = $this->requestedOn($asOf);

        $workspace = $this->caller->resolve();
        $accounts = $this->accounts->list(
            $workspace,
            $includeArchived,
            $includeClosed,
            $pageSize,
            ($requestedPage - 1) * $pageSize,
            $accountKind,
        );

        $latest = $this->snapshots->findLatestForAccounts(
            $workspace,
            array_map(static fn (Account $account): string => $account->id, $accounts),
            $requestedOn,
        );

        return new AccountPage(
            items: array_map(
                fn (Account $account): AccountView => AccountView::fromAccount(
                    $account,
                    valuation: $this->valuation($account, $requestedOn, $latest[$account->id] ?? null),
                ),
                $accounts,
            ),
            page: $requestedPage,
            perPage: $pageSize,
            total: $this->accounts->count($workspace, $includeArchived, $includeClosed, $accountKind),
        );
    }

    private function valuation(Account $account, \DateTimeImmutable $requestedOn, ?AccountBalanceSnapshot $snapshot): ValuationView
    {
        $valuation = AccountValuation::of(
            new AccountBalanceSnapshots(null === $snapshot ? [] : [$snapshot]),
            $requestedOn,
        );

        return ValuationView::of(
            $account->id,
            $requestedOn,
            $valuation,
            $this->assets->findByCode($account->assetCode),
        );
    }

    private function requestedOn(?string $asOf): \DateTimeImmutable
    {
        if (null === $asOf || '' === $asOf) {
            return BusinessDay::fromDateTime($this->clock->now())->date;
        }

        try {
            return BusinessDay::fromIsoDate($asOf)->date;
        } catch (\Throwable $exception) {
            throw new InvalidAccountBalanceInput('The valuation date must be an ISO 8601 calendar day.', previous: $exception);
        }
    }
}
