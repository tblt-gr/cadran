<?php

declare(strict_types=1);

namespace App\Module\Foundation\Application;

final readonly class DemoAccountBalanceAdjustment
{
    public function __construct(
        public string $accountId,
        public \DateTimeImmutable $bookedOn,
        public int $cents,
        public int $transfers,
    ) {
    }
}
