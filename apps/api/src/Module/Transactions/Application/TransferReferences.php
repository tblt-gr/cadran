<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * Resolves and validates the two accounts a transfer connects.
 *
 * On creation the accounts are locked with `SELECT ... FOR UPDATE`, always in
 * ascending identifier order regardless of which side the caller named
 * source or target, so two concurrent transfers between the same pair of
 * accounts wait on each other instead of deadlocking.
 */
final readonly class TransferReferences
{
    public function __construct(private AccountRepository $accounts)
    {
    }

    /**
     * @return array{0: Account, 1: Account} source then target
     */
    public function lockForCreation(
        WorkspaceScope $workspace,
        string $sourceAccountId,
        string $targetAccountId,
        \DateTimeImmutable $bookedOn,
        \DateTimeImmutable $today,
        \DateTimeImmutable $now,
    ): array {
        if ($sourceAccountId === $targetAccountId) {
            throw new InvalidTransferRule(InvalidTransferRule::SAME_ACCOUNT);
        }

        $resolved = [];
        foreach (self::ascending($sourceAccountId, $targetAccountId) as $id) {
            $account = $this->accounts->findForUpdate($workspace, $id);
            if (null === $account) {
                throw new TransferNotFound();
            }
            self::assertActive($account);
            self::assertDated($account, $bookedOn, $today);

            $used = $account->markUsed($now);
            if ($used !== $account && !$this->accounts->update($used, $account->version)) {
                throw new TransferConflict('A transfer account changed concurrently.');
            }
            $resolved[$id] = $used;
        }

        return [$resolved[$sourceAccountId], $resolved[$targetAccountId]];
    }

    /**
     * On edit, neither account changes and neither is written, so a plain
     * read validates the (possibly changed) booked date without taking a
     * lock. Unlike creation, an edit does not require the accounts to still
     * be active — correcting a label or a fee on a transfer whose account
     * was archived since must keep working, exactly like a plain
     * transaction's own edit path ({@see TransactionReferences::accountForExisting()}).
     *
     * @return array{0: Account, 1: Account} source then target
     */
    public function readForEdit(
        WorkspaceScope $workspace,
        string $sourceAccountId,
        string $targetAccountId,
        \DateTimeImmutable $bookedOn,
        \DateTimeImmutable $today,
    ): array {
        $resolved = [];
        foreach ([$sourceAccountId, $targetAccountId] as $id) {
            $account = $this->accounts->find($workspace, $id);
            if (null === $account) {
                throw new TransferNotFound();
            }
            self::assertDated($account, $bookedOn, $today);
            $resolved[$id] = $account;
        }

        return [$resolved[$sourceAccountId], $resolved[$targetAccountId]];
    }

    /** @return list<string> */
    private static function ascending(string $sourceAccountId, string $targetAccountId): array
    {
        $ids = [$sourceAccountId, $targetAccountId];
        sort($ids);

        return $ids;
    }

    private static function assertActive(Account $account): void
    {
        if (null !== $account->archivedAt || $account->isClosed()) {
            throw new InvalidTransferInput('A transfer requires two active accounts.');
        }
    }

    private static function assertDated(Account $account, \DateTimeImmutable $bookedOn, \DateTimeImmutable $today): void
    {
        if ($bookedOn < $account->openedOn || $bookedOn > $today) {
            throw new InvalidTransferInput('The booked date is outside an account open period.');
        }
    }
}
