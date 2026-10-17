<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Mapping;

/**
 * The cell overrides of one month sheet, on the movement amount columns (B, D, F, H).
 * A cached cell is read as its stored value without parsing its formula; a skipped
 * cell is left out whole. The reason of a skip is for the owner's file only: it is
 * never printed.
 */
final readonly class MonthCellOverrides
{
    /**
     * @param list<string>          $cached  cell coordinates
     * @param array<string, string> $skipped cell coordinate => reason
     */
    public function __construct(
        public array $cached = [],
        public array $skipped = [],
    ) {
    }

    public function isCached(string $cell): bool
    {
        return in_array($cell, $this->cached, true);
    }

    public function isSkipped(string $cell): bool
    {
        return isset($this->skipped[$cell]);
    }
}
