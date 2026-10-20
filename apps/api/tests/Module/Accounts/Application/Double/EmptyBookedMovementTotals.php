<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application\Double;

use App\Module\Accounts\Application\BookedMovementTotals;
use App\Module\Accounts\Domain\MovementTotal;
use App\Module\Foundation\Domain\WorkspaceScope;

final class EmptyBookedMovementTotals implements BookedMovementTotals
{
    public function forWindows(WorkspaceScope $workspace, array $windows): array
    {
        $result = [];
        foreach ($windows as $window) {
            $result[$window->key()] = new MovementTotal(null, 0, 0, false);
        }

        return $result;
    }
}
