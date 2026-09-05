<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

/**
 * Raised when a submitted override would cover days a standing override of the
 * same kind already claims. The submission is well formed; only what the
 * account already says refuses it, so it is answered as a conflict rather than
 * as invalid input.
 */
final class OverlappingAccountRuleOverride extends \DomainException
{
}
