<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application\Double;

use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Catalog\Domain\AccountKind;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * An account store for the use cases whose behaviour is not about SQL. It
 * filters on the workspace exactly like the database does, so a use case that
 * forgot to pass a scope fails here too rather than only in integration.
 */
final class InMemoryAccountRepository implements AccountRepository
{
    /** @var list<Account> */
    private array $accounts;

    public function __construct(Account ...$accounts)
    {
        $this->accounts = array_values($accounts);
    }

    public function find(WorkspaceScope $workspace, string $id): ?Account
    {
        foreach ($this->accounts as $account) {
            if ($account->id === $id && $account->workspace->equals($workspace)) {
                return $account;
            }
        }

        return null;
    }

    public function findForUpdate(WorkspaceScope $workspace, string $id): ?Account
    {
        return $this->find($workspace, $id);
    }

    public function list(
        WorkspaceScope $workspace,
        bool $includeArchived,
        bool $includeClosed,
        int $limit,
        int $offset,
        ?AccountKind $kind = null,
    ): array {
        return array_slice($this->matching($workspace, $includeArchived, $includeClosed, $kind), $offset, $limit);
    }

    public function count(
        WorkspaceScope $workspace,
        bool $includeArchived,
        bool $includeClosed,
        ?AccountKind $kind = null,
    ): int {
        return count($this->matching($workspace, $includeArchived, $includeClosed, $kind));
    }

    public function listForNetWorth(WorkspaceScope $workspace, int $limit): array
    {
        return array_slice(array_values(array_filter(
            $this->accounts,
            static fn (Account $account): bool => $account->workspace->equals($workspace)
                && null === $account->archivedAt
                && $account->includeInNetWorth,
        )), 0, $limit);
    }

    public function listForNetWorthDuring(
        WorkspaceScope $workspace,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeZone $workspaceTimezone,
        int $limit,
    ): array {
        return array_slice(array_values(array_filter(
            $this->accounts,
            static fn (Account $account): bool => $account->workspace->equals($workspace)
                && $account->includeInNetWorth
                && $account->openedOn <= $to
                && (null === $account->closedOn || $account->closedOn >= $from)
                && (null === $account->archivedAt || $account->archivedAt->setTimezone($workspaceTimezone)->format('Y-m-d') > $from->format('Y-m-d')),
        )), 0, $limit);
    }

    public function listOpenDuring(
        WorkspaceScope $workspace,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeZone $workspaceTimezone,
        int $limit,
    ): array {
        return array_slice(array_values(array_filter(
            $this->accounts,
            static fn (Account $account): bool => $account->workspace->equals($workspace)
                && $account->openedOn <= $to
                && (null === $account->closedOn || $account->closedOn >= $from)
                && (null === $account->archivedAt || $account->archivedAt->setTimezone($workspaceTimezone)->format('Y-m-d') > $from->format('Y-m-d')),
        )), 0, $limit);
    }

    public function hasActiveLabel(WorkspaceScope $workspace, string $label, ?string $excludingId = null): bool
    {
        $normalized = mb_strtolower(trim($label));

        foreach ($this->accounts as $account) {
            if (!$account->workspace->equals($workspace) || null !== $account->archivedAt) {
                continue;
            }
            if ($account->id !== $excludingId && mb_strtolower(trim($account->label)) === $normalized) {
                return true;
            }
        }

        return false;
    }

    public function add(Account $account): void
    {
        $this->accounts[] = $account;
    }

    public function update(Account $account, int $expectedVersion): bool
    {
        foreach ($this->accounts as $position => $stored) {
            if ($stored->id !== $account->id || !$stored->workspace->equals($account->workspace)) {
                continue;
            }
            if ($stored->version !== $expectedVersion) {
                return false;
            }

            $this->accounts[$position] = $account;

            return true;
        }

        return false;
    }

    /**
     * @return list<Account>
     */
    private function matching(
        WorkspaceScope $workspace,
        bool $includeArchived,
        bool $includeClosed,
        ?AccountKind $kind,
    ): array {
        return array_values(array_filter($this->accounts, static function (Account $account) use (
            $workspace, $includeArchived, $includeClosed, $kind,
        ): bool {
            return $account->workspace->equals($workspace)
                && ($includeArchived || null === $account->archivedAt)
                && ($includeClosed || !$account->isClosed())
                && (null === $kind || $account->kind === $kind);
        }));
    }
}
