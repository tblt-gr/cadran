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
        public bool $preview = false,
        public ?string $previewToken = null,
    ) {
    }

    /** @param list<string> $ids */
    public static function forIds(BulkEditChanges $changes, array $ids, bool $preview = false, ?string $previewToken = null): self
    {
        return new self($changes, $ids, null, null, $preview, $previewToken);
    }

    public static function forFilters(BulkEditChanges $changes, ListTransactionsQuery $filters, int $expectedCount, bool $preview = false, ?string $previewToken = null): self
    {
        return new self($changes, null, $filters, $expectedCount, $preview, $previewToken);
    }
}
