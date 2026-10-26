<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Transactions\Domain\TransactionRepository;

final readonly class ListTransactions
{
    public const int DEFAULT_PAGE_SIZE = 50;
    public const int MAX_PAGE_SIZE = 100;
    public const int MAX_TEXT_QUERY_LENGTH = 80;

    public function __construct(
        private CallerWorkspace $caller,
        private TransactionRepository $transactions,
        private ResolveTransactionFilters $resolveFilters,
        private PresentTransaction $presentTransaction,
    ) {
    }

    public function __invoke(ListTransactionsQuery $query): TransactionPage
    {
        $workspace = $this->caller->resolve();
        $limit = $query->pageSize ?? self::DEFAULT_PAGE_SIZE;
        if ($limit < 1 || $limit > self::MAX_PAGE_SIZE) {
            throw new InvalidTransactionInput('The transaction page size is outside its bounds.');
        }

        $filters = ($this->resolveFilters)($workspace, $query);

        $after = null;
        $priorWatermark = null;
        if (null !== $query->cursor) {
            $cursor = TransactionCursor::decode($query->cursor);
            $after = $cursor->position();
            $priorWatermark = $cursor->watermark;
        }

        $currentWatermark = $this->transactions->watermark($workspace);
        if (null !== $priorWatermark && (null === $currentWatermark || !$currentWatermark->equals($priorWatermark))) {
            throw new TransactionCursorStale();
        }

        if (null === $filters) {
            return new TransactionPage([], null, false, $limit);
        }

        $found = $this->transactions->search($workspace, $filters, $limit + 1, $after);
        $hasMore = count($found) > $limit;
        $page = $hasMore ? array_slice($found, 0, $limit) : $found;

        $nextCursor = null;
        if ($hasMore) {
            $last = $page[$limit - 1];
            $watermark = $currentWatermark ?? throw new \LogicException('A page with more rows implies a live watermark.');
            $nextCursor = (new TransactionCursor($last->bookedOn, $last->id, $watermark))->encode();
        }

        return new TransactionPage($this->presentTransaction->many($page), $nextCursor, $hasMore, $limit);
    }
}
