<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Workbook;

use App\Module\Import\Domain\Block;
use App\Module\Import\Domain\ReasonCode;

/**
 * One labelled row of a movement block. A line with a `rejection` aborts the
 * year; a line without movements was excluded (`LABEL_WITHOUT_AMOUNT`,
 * `ZERO_AMOUNT`) and is only reported. The label is kept for every line so the
 * mapping skeleton lists it, whatever happened to its amount.
 */
final readonly class MovementLine
{
    /**
     * @param list<ParsedMovement> $movements
     */
    public function __construct(
        public Block $block,
        public int $row,
        public string $rawLabel,
        public string $labelKey,
        public string $labelCell,
        public string $amountCell,
        public array $movements,
        public ?ReasonCode $rejection,
    ) {
    }
}
