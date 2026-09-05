<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application\Double;

use App\Module\Foundation\Application\TransactionBoundary;

/**
 * Runs the business operation without a database behind it. What the real
 * boundary guarantees — atomicity — is asserted in the persistence tests; here
 * the point is only that the use case runs its work inside one.
 */
final class ImmediateTransactionBoundary implements TransactionBoundary
{
    public function transactional(\Closure $callback): mixed
    {
        return $callback();
    }
}
