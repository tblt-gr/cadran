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
 *
 * `minAmount` and `maxAmount` are absolute bounds (the sign of a movement is
 * ignored); `qAmount` is the free-text query read as an unsigned amount, when it
 * reads as one, and matches in any asset.
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
        public ?TransactionDirection $direction = null,
        public ?DecimalValue $qAmount = null,
    ) {
    }

    /**
     * These filters minus the four that read a column of the transaction itself: its account, nature,
     * source and state. The facet counts of those four columns apply them to a tally of the
     * combinations, so one scan serves all four however they are filtered.
     */
    public function withoutColumnFilters(): self
    {
        return new self(
            from: $this->from,
            to: $this->to,
            accountIds: [],
            states: [],
            natures: [],
            categoryIds: $this->categoryIds,
            axes: $this->axes,
            minAmount: $this->minAmount,
            maxAmount: $this->maxAmount,
            assetCode: $this->assetCode,
            sources: [],
            categorizationNone: $this->categorizationNone,
            q: $this->q,
            direction: $this->direction,
            qAmount: $this->qAmount,
        );
    }
}
