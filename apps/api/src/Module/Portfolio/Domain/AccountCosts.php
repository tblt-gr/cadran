<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain;

use App\Module\Foundation\Domain\DecimalValue;

/** The fees and taxes an account's active operations carry over a period, each stored value counted once. */
final readonly class AccountCosts
{
    public function __construct(public string $accountId, public DecimalValue $fees, public DecimalValue $taxes)
    {
    }
}
