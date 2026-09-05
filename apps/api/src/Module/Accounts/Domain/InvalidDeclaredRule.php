<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

/**
 * Raised when a value a workspace declared for a dated rule does not hold: a
 * negative ceiling, a text value that is not a token.
 *
 * It is distinct from {@see InvalidProductModel} because the same value shape
 * is declared in two places — on a reusable product model and on a per-account
 * override — and neither of them is a product model to the other.
 */
final class InvalidDeclaredRule extends \DomainException
{
}
