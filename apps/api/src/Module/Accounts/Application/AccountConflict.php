<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

/**
 * The account cannot be written as asked because the stored state moved. See
 * {@see StaleAccountVersion} for the concurrent-edit case; this class itself
 * carries the label conflict.
 */
class AccountConflict extends \RuntimeException
{
}
