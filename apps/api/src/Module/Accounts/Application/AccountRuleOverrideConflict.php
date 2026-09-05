<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

/**
 * Raised when what the account already claims refuses the submission: a
 * standing override of the same kind covering the same days, or an override
 * withdrawn between the read and the write. The caller reloads and reapplies
 * rather than correcting a field.
 */
final class AccountRuleOverrideConflict extends \RuntimeException
{
}
