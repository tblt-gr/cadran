<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

/**
 * The raw shape of a transaction search request, once the controller has
 * checked that every field is present in the allowed set and each value has
 * the right syntactic shape (UUID format, enum membership, date pattern,
 * bounded counts and lengths). Cross-field and numeric-bound validation,
 * subtree expansion and cursor handling all happen once this reaches
 * {@see ListTransactions}.
 */
final readonly class ListTransactionsQuery
{
    /**
     * @param list<string> $accountIds  UUIDs, at most 20
     * @param list<string> $states      TransactionState members
     * @param list<string> $natures     TransactionNature members
     * @param list<string> $categoryIds UUIDs, at most 20
     * @param list<string> $axes        AnalyticAxis members
     * @param list<string> $sources     TransactionSource members
     */
    public function __construct(
        public ?string $from = null,
        public ?string $to = null,
        public array $accountIds = [],
        public array $states = [],
        public array $natures = [],
        public array $categoryIds = [],
        public bool $includeDescendants = false,
        public array $axes = [],
        public ?string $minAmount = null,
        public ?string $maxAmount = null,
        public ?string $assetCode = null,
        public array $sources = [],
        public bool $categorizationNone = false,
        public ?string $q = null,
        public bool $includeVoided = false,
        public ?int $pageSize = null,
        public ?string $cursor = null,
    ) {
    }
}
