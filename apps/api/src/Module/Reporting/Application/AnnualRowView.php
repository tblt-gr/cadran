<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

final readonly class AnnualRowView
{
    /** @param array<string, AnnualCellView> $cells by column id */
    public function __construct(
        public string $month,
        public string $state,
        public bool $closed,
        public bool $snapshot,
        public ?int $policyVersion,
        public int $pendingCount,
        public array $cells,
    ) {
    }
}
