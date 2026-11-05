<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

/** A filter names an account that does not exist in the caller's workspace, whether it is unknown or foreign. */
final class TransactionFilterAccountNotFound extends \RuntimeException
{
}
