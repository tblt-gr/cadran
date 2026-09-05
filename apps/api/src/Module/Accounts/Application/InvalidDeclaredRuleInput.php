<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

/**
 * Raised when the strings a client submitted for a dated rule cannot become
 * the closed types the domain accepts.
 *
 * It stays distinct from the two callers' own input errors because the same
 * shape is submitted for a product model and for an account override, and each
 * of them answers with its own problem type.
 */
class InvalidDeclaredRuleInput extends \InvalidArgumentException
{
}
