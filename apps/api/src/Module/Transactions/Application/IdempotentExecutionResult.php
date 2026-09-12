<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

final readonly class IdempotentExecutionResult
{
    /** @param array<string, mixed> $body */
    public function __construct(public array $body, public int $status, public bool $replayed)
    {
    }
}
