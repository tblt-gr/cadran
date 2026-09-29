<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\EmergencyFund;

use App\Module\Foundation\Domain\WorkspaceScope;

interface EmergencyFundPolicyRepository
{
    /** The stored policy, or null when the workspace still reads the default one. */
    public function find(WorkspaceScope $workspace): ?EmergencyFundPolicy;

    /** Inserts at expected version 0, updates otherwise; false when another write got there first. */
    public function save(EmergencyFundPolicy $policy, int $expectedVersion): bool;
}
