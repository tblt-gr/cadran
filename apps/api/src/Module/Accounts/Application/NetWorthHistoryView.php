<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

final readonly class NetWorthHistoryView
{
    /**
     * @param list<NetWorthPointView> $points oldest first
     */
    public function __construct(
        public string $asOf,
        public string $granularity,
        public array $points,
    ) {
    }
}
