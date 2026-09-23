<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Accounts\Application\NetWorthScopeTooLarge;
use App\Module\Accounts\Application\ReadMonthlyAccountFacts;
use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Categories\Application\BudgetCategoryScopeTooLarge;
use App\Module\Categories\Application\ReadBudgetCategoryFacts;
use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reporting\Domain\MonthlyLedgerCalculator;
use App\Module\Reporting\Domain\MonthlyLedgerSource;
use App\Module\Transactions\Application\MonthlyTransactionScopeTooLarge;
use App\Module\Transactions\Application\ReadMonthlyTransactionFacts;
use App\Module\Transactions\Application\ReadMonthlyTransferPairs;

final readonly class ReadMonthlyLedgerMovements
{
    public const int PAGE_SIZE = 50;

    public function __construct(
        private CallerWorkspace $caller,
        private ReadMonthlyTransactionFacts $transactions,
        private ReadMonthlyTransferPairs $transfers,
        private ReadMonthlyAccountFacts $accounts,
        private ReadBudgetCategoryFacts $categories,
    ) {
    }

    public function __invoke(
        string $kind,
        string $id,
        string $requestedMonth,
        ?string $requestedAxis,
        ?string $encodedCursor,
    ): MonthlyLedgerMovementPageView {
        if (!in_array($kind, ['income', 'expense', 'account'], true) || !self::uuid($id)) {
            throw new InvalidMonthlyLedgerQuery('The monthly ledger row is invalid.');
        }
        [$month, $axis] = ReadMonthlyLedger::query($requestedMonth, $requestedAxis);
        $cursor = null;
        if (null !== $encodedCursor && '' !== $encodedCursor) {
            $cursor = MonthlyLedgerCursor::decode($encodedCursor);
            if ($cursor->month !== $month->key()
                || $cursor->kind !== $kind
                || $cursor->rowId !== $id
                || $cursor->axis !== $axis?->value
            ) {
                throw new InvalidMonthlyLedgerQuery('The monthly ledger cursor does not match the query.');
            }
        }

        $workspace = $this->caller->resolve();
        try {
            $items = 'account' === $kind
                ? $this->accountItems($workspace, $month, $id)
                : $this->categoryItems($workspace, $month, $kind, $id, $axis?->value);
        } catch (MonthlyTransactionScopeTooLarge|NetWorthScopeTooLarge|BudgetCategoryScopeTooLarge $exception) {
            throw new MonthlyProjectionScopeTooLarge('The monthly ledger scope exceeds its bounds.', previous: $exception);
        }
        if (null !== $cursor) {
            $items = array_values(array_filter(
                $items,
                static fn (MonthlyLedgerMovementView $item): bool => [$item->bookedOn, $item->id] < [$cursor->bookedOn, $cursor->sourceId],
            ));
        }

        $hasMore = count($items) > self::PAGE_SIZE;
        $page = array_slice($items, 0, self::PAGE_SIZE);
        $nextCursor = null;
        if ($hasMore) {
            $last = $page[self::PAGE_SIZE - 1] ?? throw new \LogicException('A full monthly ledger page must have a last item.');
            $nextCursor = (new MonthlyLedgerCursor(
                $month->key(),
                $kind,
                $id,
                $axis?->value,
                $last->bookedOn,
                $last->id,
            ))->encode();
        }

        return new MonthlyLedgerMovementPageView($page, $nextCursor, $hasMore, self::PAGE_SIZE);
    }

    /** @return list<MonthlyLedgerMovementView> */
    private function categoryItems(
        WorkspaceScope $workspace,
        CalendarMonth $month,
        string $kind,
        string $id,
        ?string $axis,
    ): array {
        $categories = ($this->categories)($workspace);
        $expectedType = 'income' === $kind ? 'INCOME' : 'EXPENSE';
        $exists = [] !== array_filter($categories, static fn ($category): bool => $category->id === $id && $category->type === $expectedType);
        if (!$exists) {
            return [];
        }
        $transactions = ($this->transactions)($workspace, $month);
        $sources = MonthlyLedgerCalculator::categorySources(
            MonthlyLedgerFacts::entries($transactions->booked),
            $expectedType,
            $id,
            $axis,
        );

        return array_map(static fn (MonthlyLedgerSource $source): MonthlyLedgerMovementView => new MonthlyLedgerMovementView(
            $source->id,
            $source->transactionId,
            null,
            $source->bookedOn->format('Y-m-d'),
            $source->label,
            $source->amount->toString(),
            $source->asset->toString(),
        ), $sources);
    }

    /** @return list<MonthlyLedgerMovementView> */
    private function accountItems(
        WorkspaceScope $workspace,
        CalendarMonth $month,
        string $id,
    ): array {
        $accounts = ($this->accounts)($workspace, $month)->accounts;
        $labels = [];
        foreach ($accounts as $account) {
            $labels[$account->id] = $account->label;
        }
        if (!isset($labels[$id])) {
            return [];
        }

        $items = [];
        foreach (($this->transfers)($workspace, $month) as $pair) {
            if (!MonthlyLedgerFacts::validTransfer($pair)) {
                continue;
            }
            assert(null !== $pair->sourceAccountId && null !== $pair->targetAccountId);
            assert(null !== $pair->sourceTransactionId && null !== $pair->targetTransactionId);
            assert(null !== $pair->sourceAmount && null !== $pair->targetAmount);
            assert(null !== $pair->sourceAsset && null !== $pair->targetAsset);
            assert(null !== $pair->sourceBookedOn && null !== $pair->targetBookedOn);
            if ($pair->sourceAccountId === $id) {
                $items[] = new MonthlyLedgerMovementView(
                    (string) $pair->sourceTransactionId,
                    $pair->sourceTransactionId,
                    $pair->transferId,
                    (string) $pair->sourceBookedOn,
                    $labels[$pair->targetAccountId] ?? $pair->targetAccountId,
                    $pair->sourceAmount->toString(),
                    $pair->sourceAsset->toString(),
                    'OUT',
                    $pair->targetAccountId,
                    $labels[$pair->targetAccountId] ?? null,
                );
            } elseif ($pair->targetAccountId === $id) {
                $items[] = new MonthlyLedgerMovementView(
                    (string) $pair->targetTransactionId,
                    $pair->targetTransactionId,
                    $pair->transferId,
                    (string) $pair->targetBookedOn,
                    $labels[$pair->sourceAccountId] ?? $pair->sourceAccountId,
                    $pair->targetAmount->toString(),
                    $pair->targetAsset->toString(),
                    'IN',
                    $pair->sourceAccountId,
                    $labels[$pair->sourceAccountId] ?? null,
                );
            }
        }
        usort($items, static fn (MonthlyLedgerMovementView $left, MonthlyLedgerMovementView $right): int => [
            $right->bookedOn,
            $right->id,
        ] <=> [
            $left->bookedOn,
            $left->id,
        ]);

        return $items;
    }

    private static function uuid(string $value): bool
    {
        return 1 === preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D', $value);
    }
}
