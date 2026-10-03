<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

/** The requested range holds more transaction facts than one bounded read returns. */
final class AccountTransactionScopeTooLarge extends \RuntimeException
{
}
