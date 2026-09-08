<?php

declare(strict_types=1);

namespace App\Module\Foundation\Application;

/**
 * A safe, machine-readable amount rejection.
 *
 * The message deliberately carries neither submitted member: amount literals
 * are sensitive and must not leak if an exception reaches a log.
 */
final class InvalidAmountInput extends \RuntimeException
{
    public function __construct(
        public readonly string $pointer,
        public readonly string $ruleCode,
        ?\Throwable $previous = null,
    ) {
        parent::__construct('A submitted amount does not satisfy its validation rule.', previous: $previous);
    }
}
