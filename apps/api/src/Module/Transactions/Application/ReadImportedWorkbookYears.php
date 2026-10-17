<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Foundation\Domain\WorkspaceScope;

/** Which yearly workbooks the workspace already holds, from the source references the import stamps on its transactions. */
final readonly class ReadImportedWorkbookYears
{
    public function __construct(private ImportedYearReader $years)
    {
    }

    /** @return list<int> */
    public function __invoke(WorkspaceScope $workspace): array
    {
        return $this->years->years($workspace);
    }
}
