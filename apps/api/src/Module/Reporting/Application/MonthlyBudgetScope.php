<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

final readonly class MonthlyBudgetScope
{
    public function __construct(
        public string $key,
        public string $type,
        public string $id,
    ) {
    }
}
