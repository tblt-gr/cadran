<?php

declare(strict_types=1);

namespace App\Module\Identity\Domain;

use App\Module\Foundation\Domain\WorkspaceScope;

interface InterfacePreferencesRepository
{
    public function find(WorkspaceScope $workspace): InterfacePreferences;

    /** @return bool false when another writer already moved past $expectedVersion */
    public function save(InterfacePreferences $preferences, int $expectedVersion): bool;
}
