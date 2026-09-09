<?php

declare(strict_types=1);

namespace App\Module\Categories\Application;

use App\Module\Foundation\Domain\WorkspaceScope;

/** Counts transaction classifications without coupling Categories to Transactions. */
interface CategoryClassificationCounter
{
    public function countForCategory(WorkspaceScope $workspace, string $categoryId): int;
}
