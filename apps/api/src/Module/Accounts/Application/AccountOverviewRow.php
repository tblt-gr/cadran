<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

/**
 * One row of an overview table: the account plus the primary group it is
 * ordered in, whose version a move of this row must send.
 */
final readonly class AccountOverviewRow
{
    public function __construct(
        public AccountView $account,
        public ?string $primaryGroupLabel,
        public ?string $primaryGroupParentLabel,
        public int $groupOrderVersion,
    ) {
    }
}
