<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

/**
 * Two active snapshots claim the same account, day and source. The
 * submission is well formed; only what the account already says refuses it.
 */
final class ConflictingAccountBalanceSnapshot extends \DomainException
{
}
