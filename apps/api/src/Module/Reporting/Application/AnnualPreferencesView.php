<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

final readonly class AnnualPreferencesView
{
    /** @param list<string> $columns */
    public function __construct(
        public array $columns,
        public string $incompleteMonths,
        public int $version,
    ) {
    }
}
