<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Workbook;

use App\Module\Import\Domain\WorkbookUsageError;

/** The year of a workbook: the `YEAR` argument, else the four leading digits of the file name. */
final class YearResolver
{
    public static function resolve(?int $explicitYear, string $path): int
    {
        if (null !== $explicitYear) {
            return self::validated($explicitYear);
        }

        if (1 !== preg_match('/^([0-9]{4})/', basename($path), $matches)) {
            throw new WorkbookUsageError('The year is unknown: start the file name with four digits or pass YEAR.');
        }

        return self::validated((int) $matches[1]);
    }

    private static function validated(int $year): int
    {
        if ($year < 2000 || $year > 2999) {
            throw new WorkbookUsageError('The year must be between 2000 and 2999.');
        }

        return $year;
    }
}
