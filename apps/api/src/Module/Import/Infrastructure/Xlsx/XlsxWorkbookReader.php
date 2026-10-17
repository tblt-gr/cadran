<?php

declare(strict_types=1);

namespace App\Module\Import\Infrastructure\Xlsx;

use App\Module\Import\Application\WorkbookReader;
use App\Module\Import\Domain\Mapping\CellOverrides;
use App\Module\Import\Domain\Workbook\ParsedWorkbook;
use App\Module\Import\Domain\Workbook\WorkbookLayoutReader;
use App\Module\Import\Domain\Workbook\YearResolver;

final class XlsxWorkbookReader implements WorkbookReader
{
    public function __construct(private readonly WorkbookLayoutReader $layout = new WorkbookLayoutReader())
    {
    }

    public function read(string $path, ?int $year = null, int $precision = 2, ?CellOverrides $overrides = null): ParsedWorkbook
    {
        $resolvedYear = YearResolver::resolve($year, $path);

        return $this->layout->read(XlsxPackage::open($path), $resolvedYear, $precision, $overrides);
    }
}
