<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

/**
 * One group of the month-end allocation, as the net worth read published it: the roll-up of its
 * subtree and, beside it, what its own accounts hold directly.
 */
final readonly class AllocationFigure
{
    public function __construct(
        public string $groupId,
        public ?string $value,
        public ?string $asset,
        public ?string $share,
        public ?string $reason,
        public ?string $parentId = null,
        public int $depth = 1,
        public ?string $directValue = null,
        public ?string $directShare = null,
        public ?string $directReason = null,
    ) {
    }
}
