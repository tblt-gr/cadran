<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain;

use App\Module\Foundation\Domain\WorkspaceScope;

interface RecapPreferencesRepository
{
    /** @param list<string> $knownAxes the default axes of a workspace that never saved a selection */
    public function find(WorkspaceScope $workspace, array $knownAxes): RecapPreferences;

    /** @return bool false when another writer already moved past $expectedVersion */
    public function save(RecapPreferences $preferences, int $expectedVersion): bool;
}
