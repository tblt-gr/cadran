<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountBalanceSnapshotRepository;
use App\Module\Accounts\Domain\AccountBalanceSnapshots;
use App\Module\Accounts\Domain\AccountValuation;
use App\Module\Catalog\Domain\BusinessDay;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reference\Application\AssetCatalog;
use Symfony\Component\Clock\ClockInterface;

/**
 * The current-day valuation of one account. Listings batch this in SQL;
 * single-account writes reuse the same latest-active lookup so a mutation
 * never publishes a fabricated MISSING reading.
 */
final readonly class ResolveAccountValuation
{
    public function __construct(
        private AccountBalanceSnapshotRepository $snapshots,
        private AssetCatalog $assets,
        private ClockInterface $clock,
    ) {
    }

    public function current(WorkspaceScope $workspace, Account $account): ValuationView
    {
        return $this->on($workspace, $account, BusinessDay::fromDateTime($this->clock->now())->date);
    }

    public function on(WorkspaceScope $workspace, Account $account, \DateTimeImmutable $requestedOn): ValuationView
    {
        $latest = $this->snapshots->findLatestForAccounts($workspace, [$account->id], $requestedOn)[$account->id] ?? null;

        return ValuationView::of(
            $account->id,
            $requestedOn,
            AccountValuation::of(
                new AccountBalanceSnapshots(null === $latest ? [] : [$latest]),
                $requestedOn,
            ),
            $this->assets->findByCode($account->assetCode),
        );
    }
}
