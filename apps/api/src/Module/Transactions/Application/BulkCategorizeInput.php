<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

/**
 * Exactly one scope: explicit identifiers, or filter criteria with the count
 * the user confirmed.
 */
final readonly class BulkCategorizeInput
{
    /** @param list<string>|null $ids */
    private function __construct(
        public string $categoryId,
        public ?array $ids,
        public ?ListTransactionsQuery $filters,
        public ?int $expectedCount,
    ) {
    }

    /** @param list<string> $ids */
    public static function forIds(string $categoryId, array $ids): self
    {
        return new self($categoryId, $ids, null, null);
    }

    public static function forFilters(string $categoryId, ListTransactionsQuery $filters, int $expectedCount): self
    {
        return new self($categoryId, null, $filters, $expectedCount);
    }
}
