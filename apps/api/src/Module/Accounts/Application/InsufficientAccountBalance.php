<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

/** A write would create or deepen a negative balance. It carries a date, never an amount. */
final class InsufficientAccountBalance extends \RuntimeException
{
    public function __construct(
        public readonly string $accountId,
        public readonly string $field,
        public readonly \DateTimeImmutable $firstNegativeOn,
    ) {
        parent::__construct('The write would put the account below zero.');
    }
}
