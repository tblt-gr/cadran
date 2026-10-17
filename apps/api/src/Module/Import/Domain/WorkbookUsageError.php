<?php

declare(strict_types=1);

namespace App\Module\Import\Domain;

/**
 * The command was used wrongly (unreadable file, `.ods` given, missing
 * extension, bad mapping file): exit code 2, not a rejection of the workbook.
 * The message is safe to print.
 */
final class WorkbookUsageError extends \RuntimeException
{
}
