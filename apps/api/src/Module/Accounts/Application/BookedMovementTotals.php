<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\MovementTotal;
use App\Module\Accounts\Domain\MovementWindow;
use App\Module\Foundation\Domain\WorkspaceScope;

interface BookedMovementTotals
{
    public const int MAX_WINDOWS = 30000;

    /**
     * @param list<MovementWindow> $windows
     *
     * @return array<string, MovementTotal>
     */
    public function forWindows(WorkspaceScope $workspace, array $windows): array;
}
