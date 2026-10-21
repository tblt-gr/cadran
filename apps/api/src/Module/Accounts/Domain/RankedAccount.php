<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

/** An account with its 1-based position among every member of its group, hidden ones included. */
final readonly class RankedAccount
{
    public function __construct(
        public Account $account,
        public int $rank,
    ) {
    }
}
