<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain;

/**
 * A stable external identifier is unique per account: two live rows claiming
 * it would be the same real movement recorded twice, which is exactly what
 * reconciliation exists to prevent.
 */
final class DuplicateSourceReference extends \DomainException
{
}
