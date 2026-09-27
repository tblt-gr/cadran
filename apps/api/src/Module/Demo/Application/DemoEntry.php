<?php

declare(strict_types=1);

namespace App\Module\Demo\Application;

/** One planned ledger movement of the demo household, before any use case runs. */
final readonly class DemoEntry
{
    public function __construct(
        public int $day,
        public string $category,
        public string $label,
        public string $counterparty,
        public int $cents,
        public string $method,
        public ?string $key = null,
        public ?string $refundsKey = null,
    ) {
    }
}
