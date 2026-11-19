<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\DayBalance;

/** The live movements of one account grouped by day, as the balance floor needs them. */
final readonly class AccountMovements
{
    /**
     * @param list<DayBalance> $days        net of the booked and pending movements of each day, ascending
     * @param bool             $mixedAssets a movement is denominated in another asset: no balance can be derived
     */
    public function __construct(
        public array $days,
        public bool $mixedAssets,
    ) {
    }
}
