<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Categories\Domain\CategoryRepository;
use App\Module\Categories\Domain\CategoryType;
use App\Module\Foundation\Domain\UuidGenerator;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\TransactionSplit;

final readonly class TransactionReferences
{
    public function __construct(
        private AccountRepository $accounts,
        private CategoryRepository $categories,
        private UuidGenerator $uuidGenerator,
    ) {
    }

    public function accountForNew(
        WorkspaceScope $workspace,
        string $accountId,
        TransactionDraft $draft,
        \DateTimeImmutable $today,
        \DateTimeImmutable $now,
    ): Account {
        $account = $this->accounts->findForUpdate($workspace, $accountId);
        if (null === $account) {
            throw new TransactionNotFound();
        }
        if (null !== $account->archivedAt || $account->isClosed()) {
            throw new InvalidTransactionInput('A new transaction requires an active account.');
        }
        $this->assertAccountAndDates($account, $draft, $today);

        $used = $account->markUsed($now);
        if ($used !== $account && !$this->accounts->update($used, $account->version)) {
            throw new TransactionConflict('The transaction account changed concurrently.');
        }

        return $account;
    }

    public function accountForExisting(
        WorkspaceScope $workspace,
        string $accountId,
        TransactionDraft $draft,
        \DateTimeImmutable $today,
    ): Account {
        $account = $this->accounts->find($workspace, $accountId);
        if (null === $account) {
            throw new TransactionNotFound();
        }
        $this->assertAccountAndDates($account, $draft, $today);

        return $account;
    }

    public function split(
        WorkspaceScope $workspace,
        string $transactionId,
        ?string $categoryId,
        TransactionDraft $draft,
        \DateTimeImmutable $now,
        ?TransactionSplit $existing = null,
    ): ?TransactionSplit {
        if (null === $categoryId) {
            return null;
        }
        $category = $this->categories->findForUpdate($workspace, $categoryId);
        if (null === $category) {
            throw new TransactionNotFound();
        }
        $keepsExistingCategory = $existing?->categoryId === $categoryId;
        if (null !== $category->archivedAt && !$keepsExistingCategory) {
            throw new InvalidTransactionInput('A transaction category must be active.');
        }
        $expectedType = $draft->amount->value->isNegative() ? CategoryType::EXPENSE : CategoryType::INCOME;
        if ($category->type !== $expectedType) {
            throw new InvalidTransactionInput('The category type must match the transaction amount sign.');
        }

        if (!$keepsExistingCategory) {
            $used = $category->markUsed($now);
            if ($used !== $category && !$this->categories->update($used, $category->version)) {
                throw new TransactionConflict('The transaction category changed concurrently.');
            }
        }

        return new TransactionSplit(
            id: $existing->id ?? $this->uuidGenerator->generate(),
            workspace: $workspace,
            transactionId: $transactionId,
            categoryId: $categoryId,
            amount: $draft->amount,
            note: null,
            createdAt: $existing->createdAt ?? $now,
        );
    }

    private function assertAccountAndDates(Account $account, TransactionDraft $draft, \DateTimeImmutable $today): void
    {
        if ($account->assetCode->toString() !== $draft->amount->asset->toString()) {
            throw new InvalidTransactionInput('The transaction asset must match its account asset.');
        }
        if ($draft->bookedOn < $account->openedOn || $draft->bookedOn > $today) {
            throw new InvalidTransactionInput('The booked date is outside the account and workspace bounds.');
        }
    }
}
