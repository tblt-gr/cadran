<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Transactions\Domain\TransactionRepository;

final readonly class ListTransactions
{
    public const int DEFAULT_PAGE_SIZE = 50;
    public const int MAX_PAGE_SIZE = 100;

    public function __construct(
        private CallerWorkspace $caller,
        private TransactionRepository $transactions,
        private AccountRepository $accounts,
    ) {
    }

    public function __invoke(?string $accountId, bool $includeVoided, ?int $pageSize, ?string $cursor): TransactionPage
    {
        $workspace = $this->caller->resolve();
        $limit = $pageSize ?? self::DEFAULT_PAGE_SIZE;
        if ($limit < 1 || $limit > self::MAX_PAGE_SIZE) {
            throw new InvalidTransactionInput('The transaction page size is outside its bounds.');
        }
        if (null !== $accountId && null === $this->accounts->find($workspace, $accountId)) {
            throw new TransactionNotFound();
        }
        $after = null === $cursor ? null : TransactionCursor::decode($cursor);
        $found = $this->transactions->list($workspace, $accountId, $includeVoided, $limit + 1, $after?->position());
        if (count($found) <= $limit) {
            return new TransactionPage(array_map(TransactionView::fromTransaction(...), $found), null);
        }
        $page = array_slice($found, 0, $limit);
        $last = $page[$limit - 1];

        return new TransactionPage(
            array_map(TransactionView::fromTransaction(...), $page),
            (new TransactionCursor($last->bookedOn, $last->id))->encode(),
        );
    }
}
