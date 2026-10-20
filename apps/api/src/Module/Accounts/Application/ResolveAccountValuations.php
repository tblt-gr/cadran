<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountBalanceSnapshotRepository;
use App\Module\Accounts\Domain\AccountBalanceSnapshots;
use App\Module\Accounts\Domain\AccountValuation;
use App\Module\Accounts\Domain\AccountValuationMode;
use App\Module\Accounts\Domain\MovementWindow;
use App\Module\Foundation\Domain\WorkspaceScope;

final readonly class ResolveAccountValuations
{
    public function __construct(private AccountBalanceSnapshotRepository $snapshots, private BookedMovementTotals $movements)
    {
    }

    /**
     * @param list<Account>            $accounts
     * @param list<\DateTimeImmutable> $days
     *
     * @return array<string, array<string, AccountValuation>>
     */
    public function forAccounts(WorkspaceScope $workspace, array $accounts, array $days): array
    {
        if (count($accounts) > 500 || count($days) > 60) {
            throw new NetWorthScopeTooLarge('A valuation batch accepts at most 500 accounts and 60 dates.');
        }
        $latest = $this->snapshots->findLatestForAccountsOnDates($workspace, array_map(static fn (Account $account): string => $account->id, $accounts), $days);
        $windows = [];
        foreach ($days as $day) {
            foreach ($accounts as $account) {
                $anchor = $latest[$day->format('Y-m-d')][$account->id] ?? null;
                if (AccountValuationMode::TRANSACTIONS === $account->valuationMode && null !== $anchor && $anchor->asOf < $day) {
                    $window = new MovementWindow($account->id, $anchor->asOf, $day, $account->assetCode);
                    $windows[$window->key()] = $window;
                }
            }
        }
        $totals = [] === $windows ? [] : $this->movements->forWindows($workspace, array_values($windows));
        $result = [];
        foreach ($days as $day) {
            $date = $day->format('Y-m-d');
            $result[$date] = [];
            foreach ($accounts as $account) {
                $anchor = $latest[$date][$account->id] ?? null;
                $window = null !== $anchor && $anchor->asOf < $day ? new MovementWindow($account->id, $anchor->asOf, $day, $account->assetCode) : null;
                $result[$date][$account->id] = AccountValuationMode::TRANSACTIONS === $account->valuationMode && null !== $anchor && null !== $window
                    ? AccountValuation::derived($anchor, $day, $totals[$window->key()])
                    : AccountValuation::of(new AccountBalanceSnapshots(null === $anchor ? [] : [$anchor]), $day, $account->valuationMode);
            }
        }

        return $result;
    }
}
