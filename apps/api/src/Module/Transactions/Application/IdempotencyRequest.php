<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

final readonly class IdempotencyRequest
{
    public function __construct(public string $key, public string $fingerprint)
    {
    }
}
