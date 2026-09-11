<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

/**
 * A safe, machine-readable split-allocation rejection, handled globally by
 * {@see \App\Module\Foundation\UI\Http\ApiProblemResponseListener} the same
 * way as {@see \App\Module\Foundation\Application\InvalidAmountInput}: the
 * rule code names the RFC 9457 problem type and the translation parameters,
 * so a controller never has to know the split rules to report them.
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
