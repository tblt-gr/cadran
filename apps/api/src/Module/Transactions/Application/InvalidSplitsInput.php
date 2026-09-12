<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

/**
 * A safe, machine-readable split-allocation rejection. Each transaction
 * controller action that can resolve a split allocation catches this
 * explicitly and reports it through
 * {@see \App\Module\Transactions\UI\Http\TransactionHttpEnvelope::invalidSplitsProblem()},
 * which turns the rule code into the RFC 9457 problem type and the
 * translation parameters — unlike
 * {@see \App\Module\Foundation\Application\InvalidAmountInput}, which the
 * global {@see \App\Module\Foundation\UI\Http\ApiProblemResponseListener}
 * handles without any controller catch. A new call site must add its own
 * catch block.
 */
final class InvalidSplitsInput extends \RuntimeException
{
    public const string TOO_MANY = 'splits.too_many';
    public const string DUPLICATE_CATEGORY = 'splits.duplicate_category';
    public const string SUM_MISMATCH = 'splits.sum_mismatch';

    /** @param array<string, string> $parameters translator placeholders for the problem detail */
    public function __construct(
        public readonly string $ruleCode,
        public readonly array $parameters = [],
    ) {
        parent::__construct('A submitted split allocation does not satisfy its validation rule.');
    }
}
