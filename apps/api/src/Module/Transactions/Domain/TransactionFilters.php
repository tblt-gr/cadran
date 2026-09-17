<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain;

use App\Module\Categories\Domain\AnalyticAxis;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;

/**
 * The fully-resolved, already-validated filter set a search of the
 * transaction listing runs under. Everything here is a parsed value, never a
 * raw client string: shape and bound validation happened before this object
 * exists, and `includeDescendants` has already been expanded into
 * `categoryIds` by the caller — this object does not know about subtrees.
 *
 * `states` is always the caller's fully-resolved scope: an empty list means
 * "every state", never an implicit default. The default state scope (PENDING
 * and BOOKED, plus VOIDED only when explicitly requested) is a business
 * decision made before this object is built, not a repository concern.
 */
final readonly class TransactionFilters
{
    /**
     * @param list<string>            $accountIds  at most 20
     * @param list<TransactionState>  $states
     * @param list<TransactionNature> $natures
     * @param list<string>            $categoryIds at most 20, subtree already expanded
     * @param list<AnalyticAxis>      $axes
     * @param list<TransactionSource> $sources
     */
    public function __construct(
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $to = null,
        public array $accountIds = [],
        public array $states = [],
        public array $natures = [],
        public array $categoryIds = [],
        public array $axes = [],
        public ?DecimalValue $minAmount = null,
        public ?DecimalValue $maxAmount = null,
        public ?AssetCode $assetCode = null,
        public array $sources = [],
        public bool $categorizationNone = false,
        public ?string $q = null,
    ) {
    }
}
