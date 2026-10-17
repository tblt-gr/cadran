<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Workbook;

/** A purchase column of a portfolio sheet, named by its row 2 header. */
final readonly class PortfolioInstrumentColumn
{
    public function __construct(
        public string $column,
        public string $header,
        public string $headerKey,
    ) {
    }
}
