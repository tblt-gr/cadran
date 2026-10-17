<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Foundation\Domain\WorkspaceScope;

interface ImportedYearReader
{
    /**
     * The distinct years of the `wb:{year}:...` source references of the workspace, voided rows included, oldest first.
     *
     * @return list<int>
     */
    public function years(WorkspaceScope $workspace): array;
}
