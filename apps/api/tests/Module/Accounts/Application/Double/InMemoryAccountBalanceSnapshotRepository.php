<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application\Double;

use App\Module\Accounts\Domain\AccountBalanceSnapshot;
use App\Module\Accounts\Domain\AccountBalanceSnapshotRepository;
use App\Module\Accounts\Domain\AccountBalanceSnapshots;
use App\Module\Accounts\Domain\AccountValuation;
use App\Module\Accounts\Domain\BalanceSnapshotSource;
use App\Module\Foundation\Domain\WorkspaceScope;

final class InMemoryAccountBalanceSnapshotRepository implements AccountBalanceSnapshotRepository
{
    /** @var list<AccountBalanceSnapshot> */
    private array $snapshots;

    public function __construct(AccountBalanceSnapshot ...$snapshots)
    {
        $this->snapshots = array_values($snapshots);
    }

    public function findForAccount(WorkspaceScope $workspace, string $accountId): AccountBalanceSnapshots
    {
        return new AccountBalanceSnapshots(array_values(array_filter(
            $this->snapshots,
            static fn (AccountBalanceSnapshot $snapshot): bool => $snapshot->workspace->equals($workspace)
                && $snapshot->accountId === $accountId,
        )));
    }

    public function find(WorkspaceScope $workspace, string $accountId, string $id): ?AccountBalanceSnapshot
    {
        foreach ($this->snapshots as $snapshot) {
            if ($snapshot->workspace->equals($workspace) && $snapshot->accountId === $accountId && $snapshot->id === $id) {
                return $snapshot;
            }
        }

        return null;
    }

    public function findActive(
        WorkspaceScope $workspace,
        string $accountId,
        \DateTimeImmutable $asOf,
        BalanceSnapshotSource $source,
    ): ?AccountBalanceSnapshot {
        foreach ($this->snapshots as $snapshot) {
            if ($snapshot->workspace->equals($workspace)
                && $snapshot->accountId === $accountId
                && $snapshot->asOf->format('Y-m-d') === $asOf->format('Y-m-d')
                && $snapshot->source === $source
                && $snapshot->isActive()
            ) {
                return $snapshot;
            }
        }

        return null;
    }

    public function findLatestForAccounts(
        WorkspaceScope $workspace,
        array $accountIds,
        \DateTimeImmutable $asOf,
    ): array {
        $latest = [];
        foreach ($accountIds as $accountId) {
            $valuation = AccountValuation::of($this->findForAccount($workspace, $accountId), $asOf);
            if (null !== $valuation->snapshot) {
                $latest[$accountId] = $valuation->snapshot;
            }
        }

        return $latest;
    }

    public function findLatestForAccountsOnDates(
        WorkspaceScope $workspace,
        array $accountIds,
        array $dates,
    ): array {
        $latest = [];
        foreach ($dates as $date) {
            $found = $this->findLatestForAccounts($workspace, $accountIds, $date);
            if ([] !== $found) {
                $latest[$date->format('Y-m-d')] = $found;
            }
        }

        return $latest;
    }

    public function pageForAccount(
        WorkspaceScope $workspace,
        string $accountId,
        int $limit,
        int $offset,
    ): array {
        $history = $this->findForAccount($workspace, $accountId)->snapshots;
        usort($history, static function (AccountBalanceSnapshot $left, AccountBalanceSnapshot $right): int {
            $byDay = $right->asOf->format('Y-m-d') <=> $left->asOf->format('Y-m-d');
            if (0 !== $byDay) {
                return $byDay;
            }

            $byRecorded = $right->recordedAt <=> $left->recordedAt;
            if (0 !== $byRecorded) {
                return $byRecorded;
            }

            return $right->id <=> $left->id;
        });

        return array_slice($history, $offset, $limit);
    }

    public function countForAccount(WorkspaceScope $workspace, string $accountId): int
    {
        return count($this->findForAccount($workspace, $accountId)->snapshots);
    }

    public function firstActiveValuedOn(WorkspaceScope $workspace): ?\DateTimeImmutable
    {
        $first = null;
        foreach ($this->snapshots as $snapshot) {
            if ($snapshot->workspace->equals($workspace) && $snapshot->isActive() && (null === $first || $snapshot->asOf < $first)) {
                $first = $snapshot->asOf;
            }
        }

        return $first;
    }

    public function add(AccountBalanceSnapshot $snapshot): void
    {
        foreach ($this->snapshots as $stored) {
            $stored->assertCompatibleWith($snapshot);
        }

        $this->snapshots[] = $snapshot;
    }

    public function update(AccountBalanceSnapshot $snapshot, int $expectedVersion): bool
    {
        foreach ($this->snapshots as $position => $stored) {
            if ($stored->id !== $snapshot->id || !$stored->workspace->equals($snapshot->workspace)) {
                continue;
            }

            if ($stored->version !== $expectedVersion) {
                return false;
            }

            $this->snapshots[$position] = $snapshot;

            return true;
        }

        return false;
    }
}
