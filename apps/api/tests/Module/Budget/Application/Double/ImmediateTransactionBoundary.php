<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Application\Double;

use App\Module\Foundation\Application\TransactionBoundary;

final class ImmediateTransactionBoundary implements TransactionBoundary
{
    public function transactional(\Closure $callback): mixed
    {
        return $callback();
    }
}
