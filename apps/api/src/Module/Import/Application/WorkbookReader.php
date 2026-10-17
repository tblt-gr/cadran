<?php

declare(strict_types=1);

namespace App\Module\Import\Application;

use App\Module\Import\Domain\ArchiveRefused;
use App\Module\Import\Domain\Mapping\CellOverrides;
use App\Module\Import\Domain\Workbook\ParsedWorkbook;
use App\Module\Import\Domain\WorkbookUsageError;

/**
 * Port to the workbook file: parses one yearly `.xlsx` into the model the
 * planner consumes. It writes nothing and touches no database.
 */
interface WorkbookReader
{
    /**
     * @param int|null           $year      the `YEAR` argument; by default the four leading digits of the file name
     * @param int                $precision decimals of the asset every amount must fit (2 for EUR)
     * @param CellOverrides|null $overrides the mapping's per-month cached and skipped cells
     *
     * @throws WorkbookUsageError unreadable file, `.ods`, wrong extension, no year, missing ext-zip
     * @throws ArchiveRefused     the archive failed a security or well-formedness control (`ARCHIVE_REFUSED`)
     */
    public function read(string $path, ?int $year = null, int $precision = 2, ?CellOverrides $overrides = null): ParsedWorkbook;
}
