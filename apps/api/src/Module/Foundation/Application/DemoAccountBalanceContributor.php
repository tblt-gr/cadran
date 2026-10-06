<?php

declare(strict_types=1);

namespace App\Module\Foundation\Application;

/** Publishes booked account effects so the demo can reconcile contributed transfers. */
interface DemoAccountBalanceContributor extends DemoDataContributor
{
    /** @return list<DemoAccountBalanceAdjustment> */
    public function balanceAdjustments(): array;
}
