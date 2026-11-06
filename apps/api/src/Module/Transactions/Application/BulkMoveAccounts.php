<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Accounts\Domain\AccountValuationMode;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\BulkMoveTarget;
use App\Module\Transactions\Domain\Transaction;

/** Reads the accounts a bulk move involves, always inside the calling workspace. */
final readonly class BulkMoveAccounts
{
    public function __construct(private AccountRepository $accounts)
    {
    }

    /**
     * An unknown, foreign or archived account is the same answer: the whole request is refused.
     *
     * @throws BulkTargetAccountNotFound
     */
    public function target(WorkspaceScope $workspace, string $accountId, bool $lock): Account
    {
        $account = $lock ? $this->accounts->findForUpdate($workspace, $accountId) : $this->accounts->find($workspace, $accountId);
        if (null === $account || null !== $account->archivedAt) {
            throw new BulkTargetAccountNotFound();
        }

        return $account;
    }

    public function markUsed(Account $account, \DateTimeImmutable $now): void
    {
        $used = $account->markUsed($now);
        if ($used !== $account && !$this->accounts->update($used, $account->version)) {
            throw new TransactionConflict('The target account changed concurrently.');
        }
    }

    public static function describe(Account $account): BulkMoveTarget
    {
        return new BulkMoveTarget(
            $account->id, $account->label, $account->assetCode->toString(), $account->openedOn, $account->closedOn,
            AccountValuationMode::PORTFOLIO === $account->valuationMode,
        );
    }

    /**
     * @param array<string, Transaction> $rows
     *
     * @return array<string, BulkMoveTarget> by account identifier
     */
    public function currents(WorkspaceScope $workspace, array $rows): array
    {
        $described = [];
        foreach ($rows as $row) {
            if (isset($described[$row->accountId])) {
                continue;
            }
            $account = $this->accounts->find($workspace, $row->accountId) ?? throw new \LogicException('A transaction always has an account.');
            $described[$row->accountId] = self::describe($account);
        }

        return $described;
    }
}
