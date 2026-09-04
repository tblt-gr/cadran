<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

/**
 * Raised when a write reaches a model that has left the working set. It is
 * distinct from an invalid model: the submission is well formed, and only the
 * state of the target refuses it.
 */
final class ProductModelIsArchived extends \DomainException
{
}
