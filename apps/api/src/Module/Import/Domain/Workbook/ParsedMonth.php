<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Workbook;

use App\Module\Import\Domain\Block;

final readonly class ParsedMonth
{
    /**
     * @param list<MovementLine> $lines    every labelled row of the four blocks, in sheet order per block
     * @param list<AccountRow>   $accounts
     */
    public function __construct(
        public int $month,
        public string $sheet,
        public array $lines,
        public array $accounts,
    ) {
    }

    /**
     * @return list<MovementLine>
     */
    public function linesOf(Block $block): array
    {
        return array_values(array_filter($this->lines, static fn (MovementLine $line): bool => $line->block === $block));
    }
}
