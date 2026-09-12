<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Categories\Domain\Category;
use App\Module\Categories\Domain\CategoryRepository;
use App\Module\Categories\Domain\CategoryType;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Foundation\Domain\UuidGenerator;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\Transaction;
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

        $this->markCategoryUsed($category, $now, $keepsExistingCategory);

        // Keeping the same category keeps its note and analytic axes too: an
        // edit unrelated to categorisation (fixing a label, say) must not
        // silently wipe an override the split already carried.
        return new TransactionSplit(
            id: $existing->id ?? $this->uuidGenerator->generate(),
            workspace: $workspace,
            transactionId: $transactionId,
            categoryId: $categoryId,
            amount: $draft->amount,
            analyticAxes: $keepsExistingCategory ? $existing->analyticAxes : $category->defaultAnalyticAxes,
            note: $keepsExistingCategory ? $existing->note : null,
            createdAt: $existing->createdAt ?? $now,
        );
    }

    /**
     * Resolves an explicit multi-row split allocation. Unlike {@see split()},
     * each row carries its own amount and analytic axes from the client, so
     * this re-checks the count, the duplicate categories and the exact sum
     * itself instead of trusting the caller's total — the allocation the
     * client computed is never assumed correct.
     *
     * @param list<TransactionSplitInput>     $inputs
     * @param array<string, TransactionSplit> $existingByCategory      splits already on the transaction, keyed by category, so
     *                                                                 a row that keeps its category keeps its identity and creation date
     * @param bool                            $allowArchivedCategories lets a category already legitimately assigned elsewhere
     *                                                                 (duplicating a transaction) carry over even if archived
     *                                                                 since, without an $existing row here, its identity is
     *                                                                 still freshly generated
     *
     * @return list<TransactionSplit>
     */
    public function splits(
        WorkspaceScope $workspace,
        string $transactionId,
        array $inputs,
        AssetAmount $transactionAmount,
        \DateTimeImmutable $now,
        array $existingByCategory = [],
        bool $allowArchivedCategories = false,
    ): array {
        if (count($inputs) > Transaction::MAX_SPLITS) {
            throw new InvalidSplitsInput(InvalidSplitsInput::TOO_MANY);
        }

        $categoryIds = array_map(static fn (TransactionSplitInput $input): string => $input->categoryId, $inputs);
        if (count($categoryIds) !== count(array_unique($categoryIds))) {
            throw new InvalidSplitsInput(InvalidSplitsInput::DUPLICATE_CATEGORY);
        }

        // Categories are locked in a total order (by identifier) rather than
        // client-submitted order: two concurrent requests naming the same
        // categories in opposite orders would otherwise be able to deadlock
        // each other's row locks instead of one of them simply waiting.
        $sortedInputs = $inputs;
        usort($sortedInputs, static fn (TransactionSplitInput $a, TransactionSplitInput $b): int => $a->categoryId <=> $b->categoryId);
        $resolvedByCategory = [];
        foreach ($sortedInputs as $input) {
            $resolvedByCategory[$input->categoryId] = $this->resolveSplit(
                $workspace, $transactionId, $input, $transactionAmount, $now,
                $existingByCategory[$input->categoryId] ?? null, $allowArchivedCategories,
            );
        }
        $splits = array_map(
            static fn (TransactionSplitInput $input): TransactionSplit => $resolvedByCategory[$input->categoryId],
            $inputs,
        );

        if ([] !== $splits) {
            $sum = ExactDecimal::sum(...array_map(static fn (TransactionSplit $split): DecimalValue => $split->amount->value, $splits));
            if (0 !== $sum->compareTo($transactionAmount->value)) {
                $missing = ExactDecimal::subtract($transactionAmount->value, $sum);
                throw new InvalidSplitsInput(InvalidSplitsInput::SUM_MISMATCH, ['%amount%' => $missing->toString()]);
            }
        }

        return $splits;
    }

    private function resolveSplit(
        WorkspaceScope $workspace,
        string $transactionId,
        TransactionSplitInput $input,
        AssetAmount $transactionAmount,
        \DateTimeImmutable $now,
        ?TransactionSplit $existing,
        bool $allowArchivedCategory = false,
    ): TransactionSplit {
        if ($input->amount->asset->toString() !== $transactionAmount->asset->toString()
            || $input->amount->value->isNegative() !== $transactionAmount->value->isNegative()
            || 0 === $input->amount->value->compareTo(DecimalValue::zero())) {
            throw new InvalidTransactionInput('A split must share its transaction asset and sign, and cannot be zero.');
        }

        $category = $this->categories->findForUpdate($workspace, $input->categoryId);
        if (null === $category) {
            throw new TransactionNotFound();
        }
        if (null !== $category->archivedAt && null === $existing && !$allowArchivedCategory) {
            throw new InvalidTransactionInput('A transaction category must be active.');
        }
        $expectedType = $transactionAmount->value->isNegative() ? CategoryType::EXPENSE : CategoryType::INCOME;
        if ($category->type !== $expectedType) {
            throw new InvalidTransactionInput('The category type must match the transaction amount sign.');
        }

        $this->markCategoryUsed($category, $now, keepsExistingCategory: null !== $existing);

        return new TransactionSplit(
            id: $existing->id ?? $this->uuidGenerator->generate(),
            workspace: $workspace,
            transactionId: $transactionId,
            categoryId: $input->categoryId,
            amount: $input->amount,
            analyticAxes: $input->analyticAxes ?? $category->defaultAnalyticAxes,
            note: $input->note,
            createdAt: $existing->createdAt ?? $now,
        );
    }

    private function markCategoryUsed(Category $category, \DateTimeImmutable $now, bool $keepsExistingCategory): void
    {
        if ($keepsExistingCategory) {
            return;
        }

        $used = $category->markUsed($now);
        if ($used !== $category && !$this->categories->update($used, $category->version)) {
            throw new TransactionConflict('The transaction category changed concurrently.');
        }
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
