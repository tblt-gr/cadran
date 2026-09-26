<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

/** One primary group of the month-end allocation, as the net worth read published it. */
final readonly class AllocationFigure
{
    public function __construct(
        public string $groupId,
        public ?string $value,
        public ?string $asset,
        public ?string $share,
        public ?string $reason,
    ) {
    }
}
