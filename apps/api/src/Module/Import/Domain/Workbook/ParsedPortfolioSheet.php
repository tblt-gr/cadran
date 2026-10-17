<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Workbook;

use App\Module\Import\Domain\Block;

/**
 * The PEA sheet (`Block::PEA`, one instrument column) or the CTO_TR sheet
 * (`Block::CTO`, up to four). Rows are in sheet order: the opening row first,
 * then the month rows present.
 */
final readonly class ParsedPortfolioSheet
{
    /**
     * @param list<PortfolioInstrumentColumn> $instruments
     * @param list<PortfolioRow>              $rows
     */
    public function __construct(
        public Block $kind,
        public string $sheet,
        public array $instruments,
        public array $rows,
    ) {
    }

    public function rowOfMonth(int $month): ?PortfolioRow
    {
        foreach ($this->rows as $row) {
            if ($row->month === $month) {
                return $row;
            }
        }

        return null;
    }

    public function openingRow(): ?PortfolioRow
    {
        foreach ($this->rows as $row) {
            if (null === $row->month) {
                return $row;
            }
        }

        return null;
    }
}
