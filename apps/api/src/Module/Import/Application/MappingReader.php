<?php

declare(strict_types=1);

namespace App\Module\Import\Application;

use App\Module\Import\Domain\Mapping\ImportMapping;
use App\Module\Import\Domain\Mapping\InvalidMapping;
use App\Module\Import\Domain\WorkbookUsageError;

/** Port to the mapping file kept outside the repository. */
interface MappingReader
{
    /**
     * @throws WorkbookUsageError when the file is missing, unreadable or too large
     * @throws InvalidMapping     when its content does not validate
     */
    public function read(string $path): ImportMapping;
}
