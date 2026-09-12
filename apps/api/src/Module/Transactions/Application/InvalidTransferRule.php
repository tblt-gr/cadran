<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

/**
 * A safe, machine-readable transfer rejection that names its own RFC 9457
 * problem type, for the two rules the API contract exposes by name.
 * {@see InvalidTransferInput} covers every other validation failure, reported
 * as the single generic transfer problem.
 */
final class InvalidTransferRule extends \RuntimeException
{
    public const string SAME_ACCOUNT = 'transfer.same_account';
    public const string AMOUNT_MISMATCH = 'transfer.amount_mismatch';

    public function __construct(
        public readonly string $ruleCode,
        ?\Throwable $previous = null,
    ) {
        parent::__construct('A submitted transfer does not satisfy its validation rule.', previous: $previous);
    }
}
