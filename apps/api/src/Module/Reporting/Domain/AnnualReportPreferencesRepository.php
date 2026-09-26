<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain;

use App\Module\Foundation\Domain\WorkspaceScope;

interface AnnualReportPreferencesRepository
{
    /** The stored preferences, or null when the workspace never saved any. */
    public function find(WorkspaceScope $workspace): ?AnnualReportPreferences;

    /** @return bool false when another writer already moved past $expectedVersion */
    public function save(AnnualReportPreferences $preferences, int $expectedVersion): bool;
}
