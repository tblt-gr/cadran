<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\EmergencyFund;

use App\Module\Budget\Domain\EmergencyFund\LiquidAccount;
use App\Module\Foundation\Domain\WorkspaceScope;

interface LiquidAccountSource
{
    /**
     * The accounts flagged for the emergency fund, open and not archived on the as-of date, valued
     * by the net-worth rule.
     *
     * @return list<LiquidAccount>
     */
    public function eligibleOn(WorkspaceScope $workspace, \DateTimeImmutable $asOf): array;
}
