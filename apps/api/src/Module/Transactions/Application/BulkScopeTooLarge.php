<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

/** A bulk operation targets more transactions than {@see BulkRecategorizeTransactions::MAX_TRANSACTIONS}. */
final class BulkScopeTooLarge extends \RuntimeException
{
}
