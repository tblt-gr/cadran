<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

final readonly class ClosePeriodInput
{
    /** @param array<string, string> $overrides condition name => reason, each naming the check it waives */
    public function __construct(
        public string $period,
        public array $overrides = [],
    ) {
    }
}
