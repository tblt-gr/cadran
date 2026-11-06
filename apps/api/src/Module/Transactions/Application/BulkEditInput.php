<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

/**
 * Exactly one scope: explicit identifiers, or filter criteria with the count
 * the user confirmed.
 */
final readonly class BulkEditInput
{
    /** @param list<string>|null $ids */
    private function __construct(
        public BulkEditChanges $changes,
        public ?array $ids,
        public ?ListTransactionsQuery $filters,
        public ?int $expectedCount,
    ) {
    }

    /** @param list<string> $ids */
    public static function forIds(BulkEditChanges $changes, array $ids): self
    {
        return new self($changes, $ids, null, null);
    }

    public static function forFilters(BulkEditChanges $changes, ListTransactionsQuery $filters, int $expectedCount): self
    {
        return new self($changes, null, $filters, $expectedCount);
    }
}
