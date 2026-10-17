<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Sheet;

/** The sheets of one opened workbook, found by name. Only the sheets asked for are read. */
interface SheetSource
{
    /**
     * @return list<string> sheet names in workbook order
     */
    public function sheetNames(): array;

    public function readSheet(string $name): Worksheet;
}
